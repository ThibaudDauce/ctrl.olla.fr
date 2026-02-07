<?php

namespace App\Support\Lektrico;

enum ChargerState: string
{
    case Available = 'A';
    case Connected = 'B';
    case NeedAuth = 'B_AUTH';
    case Paused = 'B_PAUSE';
    case Charging = 'C';
    case Error = 'E';
    case UpdatingFirmware = 'OTA';
    case Locked = 'LOCKED';
    case PausedByScheduler = 'B_SCHEDULER';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponible',
            self::Connected => 'Connecté',
            self::NeedAuth => 'En attente de lancement',
            self::Paused => 'Pause',
            self::Charging => 'En charge',
            self::Error => 'Erreur',
            self::UpdatingFirmware => 'Mise à jour en cours',
            self::Locked => 'Verrouillée',
            self::PausedByScheduler => 'Pause via le planning',
        };
    }

    public function isConnectable(): bool
    {
        return in_array($this, [self::NeedAuth, self::Paused, self::PausedByScheduler]);
    }

    public function isCharging(): bool
    {
        return $this === self::Charging;
    }
}
