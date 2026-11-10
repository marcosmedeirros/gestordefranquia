<?php
/* Shim: a página antiga pede o backend como se ainda estivesse na raiz.
   @see paginas-antigas/_ponte.php */
require_once __DIR__ . '/../_ponte.php';
require_once antigasRaiz() . '/backend/auth.php';
