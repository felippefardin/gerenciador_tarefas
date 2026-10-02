<?php
const APP_NAME = 'Gerenciador de Tarefas';
const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'gerenciador_tarefas';
const DB_USER = 'root';
const DB_PASS = '';

// Endereço usado pelos outros computadores da mesma rede local.
// Se o nome do computador mudar no Windows, altere somente esta constante.
const NETWORK_COMPUTER_NAME = '03-D000363';
const NETWORK_APP_PATH = '/gerenciador_tarefas/';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('America/Sao_Paulo');
