"""RapidOCR over HTTP, the second engine of TransactionVerification.

POST /read  body: one image (<= 10 MB)  ->  {"lines": [...], "version": "...", "ms": {decode, detect, ..., total}}
GET  /health                             ->  {"version": "..."}

One read at a time (a lock), so memory stays at one model while queue workers wait their turn; /health is answered
on its own thread even during a read, so a busy sidecar never looks wedged to the healthcheck. Receipt text is never
logged. `python server.py --warm` builds the engine (downloading the models) and exits.
"""

import json
import os
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from importlib.metadata import version

import cv2
import numpy as np
from rapidocr import EngineType, LangRec, ModelType, OCRVersion, RapidOCR

MAX_BYTES = 10 * 1024 * 1024

# PP-OCRv5 mobile detector + Arabic v5 recognizer on OpenVINO, at most 1600 px a side: ~0.4 s a receipt on 4 cores
# (OCR_THREADS). Reads the 15 real receipts as exactly as the old server detector (~3.5 s); lines() mends its splits.
THREADS = int(os.environ.get("OCR_THREADS", "4"))
ENGINE = RapidOCR(params={
    "Global.use_cls": False,
    "Global.log_level": "error",
    "Global.max_side_len": 1600,
    "Det.engine_type": EngineType.OPENVINO,
    "Rec.engine_type": EngineType.OPENVINO,
    "EngineConfig.openvino.inference_num_threads": THREADS,
    "Det.ocr_version": OCRVersion.PPOCRV5,
    "Det.model_type": ModelType.MOBILE,
    "Rec.lang_type": LangRec.ARABIC,
    "Rec.ocr_version": OCRVersion.PPOCRV5,
    "Rec.model_type": ModelType.MOBILE,
    "EngineConfig.onnxruntime.intra_op_num_threads": THREADS,
    "EngineConfig.onnxruntime.inter_op_num_threads": 1,
})
VERSION = f"rapidocr {version('rapidocr')} / PP-OCRv5 mobile det, arabic rec, openvino, max side 1600, rows joined"
READING = threading.Lock()


def lines(image, result):
    """One line per box, in RapidOCR's order, except boxes that touch on one row: the detector split one phrase
    ("10" and "EGP" in two sizes, one box eating the other's edge), so that row is read again as one strip.
    Boxes with a gap between them (a label and its value) stay separate lines."""
    rows = []
    for box, text in zip(result.boxes if result.boxes is not None else [], result.txts or []):
        ys, xs = [p[1] for p in box], [p[0] for p in box]
        item = {"top": min(ys), "bottom": max(ys), "left": min(xs), "right": max(xs), "parts": [(min(xs), text)]}
        last = rows[-1] if rows else None
        if last is not None and touching(last, item):
            last.update(top=min(last["top"], item["top"]), bottom=max(last["bottom"], item["bottom"]),
                        left=min(last["left"], item["left"]), right=max(last["right"], item["right"]))
            last["parts"] += item["parts"]
        else:
            rows.append(item)

    return [row["parts"][0][1] if len(row["parts"]) == 1 else reread(image, row) for row in rows]


def touching(a, b):
    height = min(a["bottom"] - a["top"], b["bottom"] - b["top"])
    overlap = min(a["bottom"], b["bottom"]) - max(a["top"], b["top"])
    gap = max(a["left"], b["left"]) - min(a["right"], b["right"])
    return overlap >= 0.5 * height and gap <= 0.5 * height


def reread(image, row, pad=8):
    top, left = max(0, int(row["top"]) - pad), max(0, int(row["left"]) - pad)
    strip = image[top:int(row["bottom"]) + pad, left:int(row["right"]) + pad]
    result = ENGINE(strip, use_det=False, use_cls=False, use_rec=True)
    if result.txts and result.txts[0].strip() and float(result.scores[0]) >= 0.5:
        return result.txts[0]
    # Unsure: the pieces as read, left to right.
    return " ".join(text for _, text in sorted(row["parts"]))


class Handler(BaseHTTPRequestHandler):
    # Seconds a client may stall mid-request before its thread gives up on it.
    timeout = 30

    def do_GET(self):
        if self.path == "/health":
            self.reply(200, {"version": VERSION})
        else:
            self.reply(404, {"error": "not found"})

    def do_POST(self):
        if self.path != "/read":
            return self.reply(404, {"error": "not found"})

        length = int(self.headers.get("Content-Length") or 0)
        if not 0 < length <= MAX_BYTES:
            return self.reply(413, {"error": "expected one image of at most 10 MB"})

        body = self.rfile.read(length)

        # Decoded inside the lock too: at most one decoded image in memory, whatever queues up.
        with READING:
            start = time.perf_counter()
            image = cv2.imdecode(np.frombuffer(body, np.uint8), cv2.IMREAD_COLOR)
            if image is None:
                return self.reply(400, {"error": "not an image"})

            try:
                decoded = time.perf_counter()
                # Every call names its steps: RapidOCR keeps the last call's use_* flags (reread() turns det off).
                result = ENGINE(image, use_det=True, use_cls=False, use_rec=True)
                read = time.perf_counter()
                found = lines(image, result)
            except Exception as e:  # noqa: BLE001 - any engine failure is a 500 the caller turns into a review
                return self.reply(500, {"error": type(e).__name__})

        done = time.perf_counter()
        # Numbers only. RapidOCR times its own steps; an empty result may have none.
        det, _, rec = (list(getattr(result, "elapse_list", None) or []) + [0, 0, 0])[:3]
        timings = {"decode": decoded - start, "detect": det, "recognise": rec, "join": done - read, "total": done - start}
        self.reply(200, {"lines": found, "version": VERSION, "ms": {k: round((v or 0) * 1000) for k, v in timings.items()}})

    def reply(self, status, body):
        data = json.dumps(body, ensure_ascii=False).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def log_message(self, format, *args):
        pass


if __name__ == "__main__" and "--warm" not in sys.argv:
    ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
