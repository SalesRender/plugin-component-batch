<?php
/**
 * Created for plugin-component-batch
 * Date: 02.12.2020
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace Leadvertex\Plugin\Components\Batch;


use RuntimeException;

final class BatchHandler
{

    /** @var BatchHandlerInterface|callable */
    private static $handler;

    private function __construct() {}

    /**
     * @param BatchHandlerInterface|callable $handler
     */
    public static function config(callable $handler): void
    {
        self::$handler = $handler;
    }

    public static function getInstance(): BatchHandlerInterface
    {
        if (!isset(self::$handler)) {
            throw new RuntimeException('Batch handler was not configured');
        }

        return (self::$handler instanceof BatchHandlerInterface) ? self::$handler : (self::$handler)();
    }

}