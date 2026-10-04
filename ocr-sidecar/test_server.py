"""Row joining and the HTTP handler, with a fake engine. Run at image build (Dockerfile) or:
docker run --rm -v "$PWD:/t" -w /t tv-ocr-rapid python -m unittest test_server
"""

import json
import threading
import unittest
import urllib.error
import urllib.request
from http.server import ThreadingHTTPServer
from types import SimpleNamespace

import cv2
import numpy as np

import server


def box(left, top, right, bottom):
    return [[left, top], [right, top], [right, bottom], [left, bottom]]


class FakeEngine:
    """Detection returns the given boxes; a recognition-only call (a re-read strip) returns `reread`."""

    def __init__(self, found, reread=("", 0.0), elapse=None):
        self.found, self.reread, self.elapse, self.calls = found, reread, elapse, []

    def __call__(self, image, **flags):
        self.calls.append((image.shape[:2], flags))
        if flags.get("use_det"):
            return SimpleNamespace(boxes=[b for b, _ in self.found], txts=[t for _, t in self.found],
                                   scores=[1.0] * len(self.found), elapse_list=self.elapse)
        text, score = self.reread
        return SimpleNamespace(boxes=None, txts=[text], scores=[score])


class LinesTest(unittest.TestCase):
    image = np.zeros((200, 300), np.uint8)

    def read(self, engine):
        server.ENGINE = engine
        return server.lines(self.image, engine(self.image, use_det=True))

    def test_touching_boxes_on_one_row_are_read_again_as_one_strip(self):
        engine = FakeEngine([(box(0, 0, 40, 30), "10"), (box(42, 2, 100, 30), ") EGP")], reread=("1000 EGP", 0.9))

        self.assertEqual(self.read(engine), ["1000 EGP"])
        # The union plus 8 px padding, recognition only.
        self.assertEqual(engine.calls[1], ((38, 108), {"use_det": False, "use_cls": False, "use_rec": True}))

    def test_an_unsure_reread_joins_the_pieces_left_to_right(self):
        found = [(box(42, 2, 100, 30), "EGP"), (box(0, 0, 40, 30), "10")]

        self.assertEqual(self.read(FakeEngine(found, reread=("1O0O", 0.3))), ["10 EGP"])
        self.assertEqual(self.read(FakeEngine(found, reread=("  ", 0.9))), ["10 EGP"])

    def test_a_label_and_its_value_with_a_gap_stay_separate(self):
        engine = FakeEngine([(box(0, 0, 50, 20), "Amount"), (box(150, 0, 200, 20), "2,050")])

        self.assertEqual(self.read(engine), ["Amount", "2,050"])
        self.assertEqual(len(engine.calls), 1)

    def test_boxes_on_different_rows_stay_separate(self):
        engine = FakeEngine([(box(0, 0, 50, 20), "To"), (box(0, 40, 50, 60), "01003317907")])

        self.assertEqual(self.read(engine), ["To", "01003317907"])

    def test_nothing_found_is_no_lines(self):
        server.ENGINE = FakeEngine([])

        self.assertEqual(server.lines(self.image, SimpleNamespace(boxes=None, txts=None)), [])


class HandlerTest(unittest.TestCase):
    def setUp(self):
        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), server.Handler)
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()
        self.url = f"http://127.0.0.1:{self.httpd.server_port}"

    def tearDown(self):
        self.httpd.shutdown()
        self.httpd.server_close()

    png = cv2.imencode(".png", np.zeros((60, 120), np.uint8))[1].tobytes()

    def post(self, body):
        try:
            with urllib.request.urlopen(urllib.request.Request(self.url + "/read", data=body), timeout=10) as r:
                return r.status, json.loads(r.read())
        except urllib.error.HTTPError as e:
            return e.code, json.loads(e.read())

    def test_every_read_names_its_steps_so_a_reread_cannot_leave_detection_off(self):
        # RapidOCR keeps the last call's use_* flags, so the call after a re-read must turn detection back on.
        server.ENGINE = FakeEngine([(box(0, 0, 40, 30), "10"), (box(42, 2, 100, 30), "EGP")], reread=("10 EGP", 0.9))

        for _ in range(2):
            status, body = self.post(self.png)
            body.pop("ms")
            self.assertEqual((status, body), (200, {"lines": ["10 EGP"], "version": server.VERSION}))

        detections = [flags for _, flags in server.ENGINE.calls if flags.get("use_det")]
        self.assertEqual(detections, [{"use_det": True, "use_cls": False, "use_rec": True}] * 2)

    def test_a_read_reports_its_steps_in_whole_milliseconds(self):
        server.ENGINE = FakeEngine([(box(0, 0, 50, 20), "Amount")], elapse=[0.012, None, 0.034])

        ms = self.post(self.png)[1]["ms"]

        self.assertEqual(set(ms), {"decode", "detect", "recognise", "join", "total"})
        self.assertEqual((ms["detect"], ms["recognise"]), (12, 34))
        self.assertTrue(all(isinstance(v, int) and v >= 0 for v in ms.values()))

    def test_a_result_without_step_times_still_answers(self):
        # RapidOCR's empty result (nothing found) may carry no step times.
        for elapse in (None, []):
            server.ENGINE = FakeEngine([], elapse=elapse)

            status, body = self.post(self.png)

            self.assertEqual((status, body["lines"], body["ms"]["detect"], body["ms"]["recognise"]), (200, [], 0, 0))

    def test_a_body_that_is_not_an_image_is_refused(self):
        server.ENGINE = FakeEngine([])

        self.assertEqual(self.post(b"not an image"), (400, {"error": "not an image"}))
        self.assertEqual(server.ENGINE.calls, [])


if __name__ == "__main__":
    unittest.main()
