"""End-to-end check of a built image: draw a receipt-like PNG, read it through /read, expect the amount and reference.

Runs inside the container (`docker exec -i <name> python - < smoke_test.py`), where Pillow and the server are.
Not copied into the image. Synthetic text only, never a real receipt.
"""
import io
import json
import urllib.request

from PIL import Image, ImageDraw, ImageFont

image = Image.new("RGB", (900, 260), "white")
draw = ImageDraw.Draw(image)
font = ImageFont.load_default(size=56)
draw.text((40, 40), "Amount 3,070.00 EGP", fill="black", font=font)
draw.text((40, 140), "Reference 100000000001", fill="black", font=font)
body = io.BytesIO()
image.save(body, "PNG")

# Raw bytes, as the app's RapidOcrEngine sends them.
request = urllib.request.Request("http://127.0.0.1:8080/read", data=body.getvalue(), method="POST",
                                 headers={"Content-Type": "image/png"})
with urllib.request.urlopen(request, timeout=60) as response:
    result = json.load(response)

text = " ".join(result["lines"])
assert "3,070.00" in text or "3070.00" in text, f"amount not read: {result['lines']}"
assert "100000000001" in text, f"reference not read: {result['lines']}"
print("smoke ok:", result["version"], result["ms"]["total"], "ms")
