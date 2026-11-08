"""End-to-end check of a build: draw a receipt-like PNG, read it, expect the amount and reference back.

    python smoke_test.py path/to/tv-ocr      # the frozen binary, one shot (binary.yml)
    python smoke_test.py                     # a RapidOCR server already answering on 127.0.0.1:8080

Needs only Pillow, so it runs on a CI host against an unpacked archive. Synthetic text only, never a real receipt.
"""
import io
import json
import subprocess
import sys
import tempfile
import urllib.request

from PIL import Image, ImageDraw, ImageFont

AMOUNT, REFERENCE = "3,070.00", "100000000001"


def receipt():
    image = Image.new("RGB", (900, 260), "white")
    draw = ImageDraw.Draw(image)
    font = ImageFont.load_default(size=56)
    draw.text((40, 40), f"Amount {AMOUNT} EGP", fill="black", font=font)
    draw.text((40, 140), f"Reference {REFERENCE}", fill="black", font=font)
    body = io.BytesIO()
    image.save(body, "PNG")
    return body.getvalue()


def over_http(png):
    # Raw bytes, as the app's RapidOcrEngine sends them.
    request = urllib.request.Request("http://127.0.0.1:8080/read", data=png, method="POST",
                                     headers={"Content-Type": "image/png"})
    with urllib.request.urlopen(request, timeout=60) as response:
        return json.load(response)


def over_binary(binary, png):
    # A real file, as the app's RapidOcrEngine passes one: the preprocessor's PNG on disk.
    with tempfile.NamedTemporaryFile(suffix=".png") as file:
        file.write(png)
        file.flush()
        done = subprocess.run([binary, file.name], capture_output=True, text=True, timeout=300)

    if done.returncode != 0:
        raise SystemExit(f"{binary} exited {done.returncode}: {done.stdout.strip()} {done.stderr.strip()}")

    return json.loads(done.stdout)


png = receipt()
result = over_binary(sys.argv[1], png) if len(sys.argv) > 1 else over_http(png)

text = " ".join(result["lines"])
assert AMOUNT in text or AMOUNT.replace(",", "") in text, f"amount not read: {result['lines']}"
assert REFERENCE in text, f"reference not read: {result['lines']}"
print("smoke ok:", result["version"], result["ms"]["total"], "ms")
