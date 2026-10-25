"""Row joining, the one-shot read and the HTTP handler, with a fake engine so no model is loaded.

Run by build-archive.sh before it freezes anything, or directly: python -m unittest -q test_server
"""

import json
import os
import tempfile
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


class SlicesTest(unittest.TestCase):
    def setUp(self):
        # Small images stand in for big ones: only their shape relative to the cap matters.
        self.addCleanup(setattr, server, "MAX_SIDE", server.MAX_SIDE)
        server.MAX_SIDE = 300

    @staticmethod
    def page(height, width=100, text_rows=()):
        """White, with black bands (lines of text) at the given (top, bottom) rows."""
        image = np.full((height, width, 3), 255, np.uint8)
        for top, bottom in text_rows:
            image[top:bottom] = 0
        return image

    def test_an_image_up_to_a_page_and_a_half_tall_is_read_whole(self):
        image = self.page(400, width=300)

        self.assertEqual([p.shape for p in server.slices(image)], [image.shape])

    def test_a_tall_image_the_cap_does_not_shrink_is_read_whole(self):
        # A phone screenshot: twice as tall as wide, but within the cap, so it is read at full size anyway.
        image = self.page(280, width=130)

        self.assertEqual([p.shape for p in server.slices(image)], [image.shape])

    def test_a_tall_image_is_cut_into_page_shaped_slices_on_empty_rows_covering_it_all(self):
        # Text everywhere except one empty row per page, off the exact target (140), inside the window (120-160).
        rows = [(0, 126), (127, 280), (281, 400)]
        image = self.page(400, text_rows=rows)

        pieces = server.slices(image)

        self.assertEqual([len(p) for p in pieces], [126, 154, 120])
        self.assertEqual(sum(len(p) for p in pieces), 400)
        # Each cut lands on the empty row, so every piece starts on it and no text row is split.
        self.assertTrue(all((p[0] == 255).all() for p in pieces[1:]))


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

    def test_a_tall_image_is_read_slice_by_slice_and_its_lines_joined_in_order(self):
        self.addCleanup(setattr, server, "MAX_SIDE", server.MAX_SIDE)
        server.MAX_SIDE = 300
        server.ENGINE = FakeEngine([(box(0, 0, 50, 20), "Amount")], elapse=[0.010, None, 0.020])
        tall = cv2.imencode(".png", np.full((400, 100), 255, np.uint8))[1].tobytes()

        status, body = self.post(tall)

        self.assertEqual((status, body["lines"]), (200, ["Amount"] * 3))
        self.assertEqual([shape for shape, _ in server.ENGINE.calls], [(140, 100), (140, 100), (120, 100)])
        self.assertEqual((body["ms"]["detect"], body["ms"]["recognise"]), (30, 60))

    def test_a_body_that_is_not_an_image_is_refused(self):
        server.ENGINE = FakeEngine([])

        self.assertEqual(self.post(b"not an image"), (400, {"error": "not an image"}))
        self.assertEqual(server.ENGINE.calls, [])


class OneShotTest(unittest.TestCase):
    """`server.py <path>` -- read_file() in-process. A subprocess could not be given a FakeEngine,
    and loading the real models is what FakeEngine exists to avoid; the CI artifact check covers
    the frozen binary end to end."""

    png = cv2.imencode(".png", np.zeros((60, 120), np.uint8))[1].tobytes()

    def file(self, body):
        handle, path = tempfile.mkstemp(suffix=".png")
        self.addCleanup(os.unlink, path)
        with os.fdopen(handle, "wb") as f:
            f.write(body)
        return path

    def test_a_file_is_read_into_the_body_a_post_would_answer(self):
        server.ENGINE = FakeEngine([(box(0, 0, 40, 30), "10"), (box(42, 2, 100, 30), "EGP")], reread=("10 EGP", 0.9))

        answer, status = server.read_file(self.file(self.png))

        self.assertEqual((status, answer["lines"], answer["version"]), (0, ["10 EGP"], server.VERSION))
        self.assertEqual(set(answer["ms"]), {"decode", "detect", "recognise", "join", "total"})

    def test_both_transports_answer_the_same_lines_and_version(self):
        # The version string lands in the stored audit trail: drift would split one engine into two.
        server.ENGINE = FakeEngine([(box(0, 0, 50, 20), "Amount")], elapse=[0.012, None, 0.034])
        httpd = ThreadingHTTPServer(("127.0.0.1", 0), server.Handler)
        threading.Thread(target=httpd.serve_forever, daemon=True).start()
        self.addCleanup(httpd.server_close)
        self.addCleanup(httpd.shutdown)

        request = urllib.request.Request(f"http://127.0.0.1:{httpd.server_port}/read", data=self.png)
        with urllib.request.urlopen(request, timeout=10) as r:
            over_http = json.loads(r.read())
        answer, _ = server.read_file(self.file(self.png))

        self.assertEqual(answer["lines"], over_http["lines"])
        self.assertEqual(answer["version"], over_http["version"])
        self.assertEqual(list(answer["ms"]), list(over_http["ms"]))

    def test_a_file_that_is_not_an_image_is_refused(self):
        server.ENGINE = FakeEngine([])

        self.assertEqual(server.read_file(self.file(b"not an image")), ({"error": "not an image"}, 3))
        self.assertEqual(server.ENGINE.calls, [])

    def test_a_file_over_the_limit_is_refused_before_it_is_opened(self):
        self.addCleanup(setattr, server, "MAX_BYTES", server.MAX_BYTES)
        server.MAX_BYTES = len(self.png) - 1
        server.ENGINE = FakeEngine([])

        answer, status = server.read_file(self.file(self.png))

        self.assertEqual((answer, status), ({"error": "expected one image of at most 10 MB"}, 2))
        self.assertEqual(server.ENGINE.calls, [])

    def test_an_empty_file_is_refused(self):
        server.ENGINE = FakeEngine([])

        self.assertEqual(server.read_file(self.file(b""))[1], 2)
        self.assertEqual(server.ENGINE.calls, [])

    def test_a_path_that_cannot_be_read_is_refused_rather_than_raising(self):
        # PHP parses stdout: a traceback would be unparseable where an error body is not.
        self.assertEqual(server.read_file("/nonexistent/receipt.png"), ({"error": "could not read the file"}, 2))

    def test_an_engine_failure_is_reported_as_its_type(self):
        class Boom:
            def __call__(self, image, **flags):
                raise ValueError("engine died")

        server.ENGINE = Boom()

        self.assertEqual(server.read_file(self.file(self.png)), ({"error": "ValueError"}, 4))


if __name__ == "__main__":
    unittest.main()
