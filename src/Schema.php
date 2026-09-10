<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent;

use AgentsFullDuplex\RealtimeAgent\Data\SchemaType;

final class Schema
{
    public static function string(): SchemaType
    {
        return SchemaType::make('string');
    }

    public static function integer(): SchemaType
    {
        return SchemaType::make('integer');
    }

    public static function number(): SchemaType
    {
        return SchemaType::make('number');
    }

    public static function boolean(): SchemaType
    {
        return SchemaType::make('boolean');
    }

    /** @param array<string, mixed>|null $items */
    public static function array(SchemaType|array|null $items = null): SchemaType
    {
        return SchemaType::make('array')->items($items);
    }

    /** @param array<string, SchemaType|array<string, mixed>> $properties */
    public static function object(array $properties = []): SchemaType
    {
        return SchemaType::make('object')->properties($properties);
    }
}
