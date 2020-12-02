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

    private static BatchHandlerInterface $handler;

    private function __construct() {}

    public static function config(BatchHandlerInterface $handler): void
    {
        self::$handler = $handler;
    }

    public static function getInstance(): BatchHandlerInterface
    {
        if (!isset(self::$handler)) {
            throw new RuntimeException('Batch form registry was not configured');
        }

        return self::$handler;
    }

}