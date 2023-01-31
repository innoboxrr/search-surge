<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitb02d3be728affc914778bc036e218ed9
{
    public static $prefixLengthsPsr4 = array (
        'D' => 
        array (
            'Desar\\SearchSurge\\' => 18,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Desar\\SearchSurge\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitb02d3be728affc914778bc036e218ed9::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitb02d3be728affc914778bc036e218ed9::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInitb02d3be728affc914778bc036e218ed9::$classMap;

        }, null, ClassLoader::class);
    }
}
