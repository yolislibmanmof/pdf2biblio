<?php
/**
 * Plugin Name: PDF2Biblio
 * Plugin URI: https://github.com/yolislibmanmof/pdf2biblio
 * Description: Impor bibliografi otomatis dari PDF (KDT, hak cipta, sampul, metadata) + perkayaan OpenLibrary & Google Books + cover + lampiran PDF.
 * Version: 2.4.0
 * Author: Yolis Libman
 * Author URI: https://github.com/yolislibmanmof
 */
use SLiMS\Plugins;

// PENTING: path harus file yang BENAR-BENAR ADA di disk.
// index.php = pintu tunggal (router AJAX + UI). manager.php TIDAK dipakai.
Plugins::getInstance()->registerMenu('bibliography', 'PDF2Biblio', __DIR__ . '/index.php');