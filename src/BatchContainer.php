<?php
/**
 * Created for plugin-component-batch
 * Date: 09.12.2020
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace Leadvertex\Plugin\Components\Batch;


use Leadvertex\Plugin\Components\Form\Form;
use RuntimeException;

final class BatchContainer
{

    /** @var callable */
    private static $forms;

    private static BatchHandlerInterface $handler;

    private function __construct() {}

    public static function config(callable $forms, BatchHandlerInterface $handler): void
    {
        self::$forms = $forms;
        self::$handler = $handler;
    }

    public static function getForm(int $number): ?Form
    {
        if (!isset(self::$forms)) {
            throw new RuntimeException('Batch forms was not configured');
        }

        $form = self::$forms;
        return $form($number);
    }

    public static function getHandler(): BatchHandlerInterface
    {
        if (!isset(self::$forms)) {
            throw new RuntimeException('Batch handler was not configured');
        }

        return self::$handler;
    }

}