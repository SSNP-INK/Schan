<?php

use Gettext\Translator;
use Gettext\Translations;

setlocale(LC_ALL, SCHAN_LOCALE);

require 'inc/gettext/src/autoloader.php';

$translations = Translations::fromPoFile('locale/' . SCHAN_LOCALE . '/schan.po');
$translator = new Translator();
$translator->loadTranslations($translations);
$translator->register();
