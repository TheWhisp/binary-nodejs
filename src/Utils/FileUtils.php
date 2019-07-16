<?php
/**
 * Copyright © Vaimo Group. All rights reserved.
 * See LICENSE_VAIMO.txt for license details.
 */
namespace Mouf\NodeJsInstaller\Utils;

class FileUtils
{
    public static function composePath()
    {
        $pathSegments = \array_map(function ($item) {
            return \rtrim($item, \DIRECTORY_SEPARATOR);
        }, \func_get_args());

        return \implode(
            \DIRECTORY_SEPARATOR,
            \array_filter($pathSegments)
        );
    }
}
