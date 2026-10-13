<?php

namespace App\Services\Telegram;

enum TelegramErrorType: string
{
    case InvalidToken = 'invalid_token';
    case ChatNotFound = 'chat_not_found';
    case Forbidden = 'forbidden';
    case MissingPermissions = 'missing_permissions';
    case InvalidMedia = 'invalid_media';
    case BadRequest = 'bad_request';
    case FloodControl = 'flood_control';
    case Timeout = 'timeout';
    case Network = 'network';
    case ServerError = 'server_error';
    case Unknown = 'unknown';

    /**
     * Temporary errors that are worth retrying.
     */
    public function isTemporary(): bool
    {
        return in_array($this, [self::FloodControl, self::Timeout, self::Network, self::ServerError, self::Unknown], true);
    }

    /**
     * Errors that mean the bot itself is unusable.
     */
    public function isBotFailure(): bool
    {
        return $this === self::InvalidToken;
    }

    /**
     * Errors that mean the bot cannot post in the destination.
     */
    public function isPermissionFailure(): bool
    {
        return in_array($this, [self::ChatNotFound, self::Forbidden, self::MissingPermissions], true);
    }
}
