<?php
/**
 * Created for plugin-component-batch
 * Date: 26.11.2020
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace Leadvertex\Plugin\Components\Batch;


use Leadvertex\Plugin\Components\Form\Form;
use RuntimeException;

final class BatchFormRegistry
{

    /** @var callable */
    private static $resolver;

    private function __construct() {}

    public static function config(callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * @param int $number
     * @return Form|null
     * @throws RuntimeException
     */
    public static function getForm(int $number): ?Form
    {
        if (!isset(self::$resolver)) {
            throw new RuntimeException('Batch form registry was not configured');
        }

        $resolver = self::$resolver;
        return $resolver($number);
    }

}