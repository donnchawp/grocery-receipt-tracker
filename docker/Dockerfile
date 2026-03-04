FROM wordpress:latest

RUN apt-get update && \
    apt-get install -y tesseract-ocr tesseract-ocr-eng && \
    rm -rf /var/lib/apt/lists/*
