FROM python:3.11-slim
WORKDIR /srv/ocr
COPY requirements.txt /srv/ocr/requirements.txt
RUN pip install --no-cache-dir -r requirements.txt
COPY . /srv/ocr
EXPOSE 8001
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8001"]
