<?php

declare(strict_types=1);

namespace App\Enums;

enum ScanStage: string
{
    case Extract = 'extract';
    case Manifest = 'manifest';
    case Files = 'files';
    case Classes = 'classes';
    case Routes = 'routes';
    case Models = 'models';
    case Schema = 'schema';
    case Views = 'views';
    case Links = 'links';
    case Insights = 'insights';
    case Layout = 'layout';

    /** Ordered pipeline definition. */
    public static function pipeline(): array
    {
        return [
            self::Extract,
            self::Manifest,
            self::Files,
            self::Classes,
            self::Routes,
            self::Models,
            self::Schema,
            self::Views,
            self::Links,
            self::Insights,
            self::Layout,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::Extract => 'Unpacking archive',
            self::Manifest => 'Reading composer manifest',
            self::Files => 'Indexing file tree',
            self::Classes => 'Parsing PHP with AST',
            self::Routes => 'Tracing route table',
            self::Models => 'Mapping Eloquent models',
            self::Schema => 'Rebuilding the database schema',
            self::Views => 'Resolving Blade views',
            self::Links => 'Resolving the dependency graph',
            self::Insights => 'Computing architectural insights',
            self::Layout => 'Projecting into 3D space',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Extract => 'Safely unpacks the uploaded project and verifies it is a Laravel codebase.',
            self::Manifest => 'Reads composer.json, framework version, packages and configuration surface.',
            self::Files => 'Walks every directory and builds the file inventory that powers the explorer.',
            self::Classes => 'Parses each PHP file into an abstract syntax tree to find classes, methods and imports.',
            self::Routes => 'Evaluates the fluent route registrar to reconstruct the real route table.',
            self::Models => 'Detects fillables, casts and every Eloquent relationship between models.',
            self::Schema => 'Reads migrations to rebuild tables, columns and foreign keys.',
            self::Views => 'Follows @extends / @include / components to link views to each other and to routes.',
            self::Links => 'Cross references imports and type hints to wire the whole graph together.',
            self::Insights => 'Scores complexity, finds god classes, orphans, cycles and untested code.',
            self::Layout => 'Assigns each node to an architectural tier and computes stable 3D coordinates.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Extract => 'archive',
            self::Manifest => 'file-json',
            self::Files => 'folder-tree',
            self::Classes => 'braces',
            self::Routes => 'route',
            self::Models => 'database',
            self::Schema => 'table',
            self::Views => 'layout',
            self::Links => 'share',
            self::Insights => 'sparkles',
            self::Layout => 'cube',
        };
    }

    /** Rough share of the total progress bar spent in this stage. */
    public function weight(): int
    {
        return match ($this) {
            self::Extract => 8,
            self::Manifest => 3,
            self::Files => 9,
            self::Classes => 34,
            self::Routes => 9,
            self::Models => 9,
            self::Schema => 6,
            self::Views => 8,
            self::Links => 6,
            self::Insights => 5,
            self::Layout => 3,
        };
    }

    public function next(): ?self
    {
        $pipeline = self::pipeline();
        $index = array_search($this, $pipeline, true);

        return $index === false || ! isset($pipeline[$index + 1]) ? null : $pipeline[$index + 1];
    }

    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
