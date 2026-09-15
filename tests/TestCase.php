<?php

namespace AltDesign\SearchService\Tests;

use AltDesign\SearchService\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
}
