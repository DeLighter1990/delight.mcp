#!/usr/bin/env python3
"""
Микросервис для генерации embeddings
"""

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field
from sentence_transformers import SentenceTransformer
from typing import List, Optional
import uvicorn

# Конфигурация
MODEL_NAME = 'paraphrase-multilingual-MiniLM-L12-v2'

# Инициализация FastAPI
app = FastAPI(
    title="Embedding Generation Service",
    description="Микросервис для генерации embeddings из текста",
    version="1.0.0"
)

# Глобальная переменная для модели
model: Optional[SentenceTransformer] = None


# Pydantic модели
class TextInput(BaseModel):
    text: str = Field(..., description="Текст для генерации embedding")

    class Config:
        json_schema_extra = {
            "example": {
                "text": "Пример текста для обработки"
            }
        }

# Lifecycle events
@app.on_event("startup")
async def startup_event():
    """Загрузка модели при старте сервиса"""
    global model
    print(f"🤖 Загрузка модели {MODEL_NAME}...")
    model = SentenceTransformer(MODEL_NAME)
    print("✅ Модель загружена успешно")


# API endpoint
@app.post("/generate_embedding", response_model=List[float]) # Изменено: возвращаем List[float]
async def generate_embedding(input_data: TextInput):
    """
    Генерирует embedding для текста

    Используется для:
    - Генерации embedding для поискового запроса
    - Генерации embedding для отдельного чанка документации
    """
    # Проверка здоровья
    if model is None:
        raise HTTPException(status_code=503, detail="Модель не загружена")

    if not input_data.text.strip():
        raise HTTPException(status_code=400, detail="Текст не может быть пустым")

    try:
        # Генерируем embedding
        embedding = model.encode(input_data.text, convert_to_numpy=True)

        return embedding.tolist() # Изменено: возвращаем только список
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Ошибка генерации embedding: {str(e)}")


# Запуск сервера
if __name__ == "__main__":
    uvicorn.run(
        "main:app",
        host="0.0.0.0",
        port=8000,
        reload=True,
        log_level="info"
    )
