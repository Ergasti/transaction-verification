# RapidOCR sidecar for TransactionVerification (second OCR engine). Published by the package CI as
# ghcr.io/ergasti/transaction-verification-ocr:<version> on each release; apps pull that, or build this folder.
# Pinned by digest (python 3.11.16), with every package pinned in requirements.txt: a rebuild is the image measured.
FROM python:3.11-slim@sha256:e41613d42d4891e4930f79523f93f81bbc7632584ec65e36ab055f41a800b41e

# OpenCV refuses to decode more pixels than this; the app sends at most 8 M (ImagePreprocessor's output cap).
ENV CV_IO_MAX_IMAGE_PIXELS=8000000

WORKDIR /srv
COPY requirements.txt .
RUN pip install --no-cache-dir --no-deps -r requirements.txt

COPY server.py .
# Downloads the models (SHA256-checked by rapidocr) into the image, so the container never fetches anything.
RUN python server.py --warm
# The row joining and handler tests (fake engine): a broken sidecar never becomes an image.
COPY test_server.py .
RUN python -m unittest -q test_server

RUN useradd --system --no-create-home ocr
USER ocr
EXPOSE 8080
# /health answers during a read (server.py), so a busy sidecar is never taken for a wedged one.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8080/health', timeout=4)"
CMD ["python", "server.py"]
