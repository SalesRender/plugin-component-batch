<?php
/**
 * Created for plugin-component-batch
 * Date: 09.12.2020
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace SalesRender\Plugin\Components\Batch;


use SalesRender\Plugin\Components\Batch\Exceptions\BatchContainerException;
use SalesRender\Plugin\Components\Form\Form;

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

    /**
     * @param int $number
     * @return Form|null
     * @throws BatchContainerException
     */
    public static function getForm(int $number): ?Form
    {
        if (!isset(self::$forms)) {
            throw new BatchContainerException('Batch forms was not configured', 100);
        }

        $form = self::$forms;
        return $form($number);
    }

    /**
     * @return BatchHandlerInterface
     * @throws BatchContainerException
     */
    public static function getHandler(): BatchHandlerInterface
    {
        if (!isset(self::$forms)) {
            throw new BatchContainerException('Batch handler was not configured', 200);
        }

        return self::$handler;
    }

}