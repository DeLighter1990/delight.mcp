<?php

namespace Delight\Mcp\Enum;

/**
 * Целевая аудитория ресурса
 */
enum ResourceAudience: string
{
    /** Пользователь (человек) */
    case USER = 'user';
    /** AI-ассистент */
    case ASSISTANT = 'assistant';
}
