<?php

namespace App\Support\Modules;

/**
 * One case per directory in app/Modules. `->name` is the directory name,
 * `->value` the snake_case key used in MODULE_<KEY> env overrides.
 */
enum Module: string
{
    case Casting = 'casting';
    case Catering = 'catering';
    case CustomServers = 'custom_servers';
    case Discord = 'discord';
    case Events = 'events';
    case Files = 'files';
    case Friends = 'friends';
    case Gallery = 'gallery';
    case GameServers = 'game_servers';
    case Games = 'games';
    case Hosts = 'hosts';
    case Identity = 'identity';
    case Infoscreen = 'infoscreen';
    case Jukebox = 'jukebox';
    case Lancache = 'lancache';
    case Lfg = 'lfg';
    case News = 'news';
    case Notifications = 'notifications';
    case Preflight = 'preflight';
    case Presence = 'presence';
    case Provisioning = 'provisioning';
    case Recap = 'recap';
    case Registration = 'registration';
    case Schedule = 'schedule';
    case Seating = 'seating';
    case Stats = 'stats';
    case Teams = 'teams';
    case Tournaments = 'tournaments';
    case Voice = 'voice';
    case Voting = 'voting';
    case WebVoice = 'web_voice';

    public function key(): string
    {
        return $this->value;
    }

    public function isCore(): bool
    {
        return match ($this) {
            self::Events, self::Registration, self::Seating, self::Teams, self::Games,
            self::Tournaments, self::Identity, self::Notifications, self::Preflight => true,
            default => false,
        };
    }

    /**
     * Hard dependencies: modules whose own pages/actions call into these
     * (derived from tests/Architecture/module-dependencies.php, runtime use
     * only). Everything else is reached through events/guards and tolerates
     * the other module being off.
     *
     * @return list<self>
     */
    public function requires(): array
    {
        return match ($this) {
            self::CustomServers, self::Lancache => [self::Hosts],
            self::Provisioning => [self::Hosts, self::Lancache],
            self::Recap => [self::Gallery, self::Jukebox, self::Voting, self::Presence],
            self::News => [self::Gallery],
            self::Gallery => [self::Files],
            self::Stats => [self::Voting],
            self::Friends => [self::Presence],
            default => [],
        };
    }
}
