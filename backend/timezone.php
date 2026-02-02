<?php
/**
 * Configuração de Timezone para todo o sistema
 * Garante que todas as datas e horários usem o fuso horário de São Paulo/Brasília
 * 
 * Este arquivo deve ser incluído no início de todos os scripts PHP que manipulam datas/horários.
 */

// Define timezone padrão para todo o sistema: São Paulo/Brasília (UTC-3)
date_default_timezone_set('America/Sao_Paulo');

/**
 * Retorna o DateTime atual no timezone de Brasília
 * @return DateTime
 */
function getBrasiliaDateTime(): DateTime {
    return new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
}

/**
 * Retorna a data/hora atual no formato MySQL (Y-m-d H:i:s) no timezone de Brasília
 * @return string
 */
function getBrasiliaDateTimeString(): string {
    return (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
}

/**
 * Converte uma string de data/hora para o timezone de Brasília
 * @param string $dateTimeString
 * @return DateTime
 */
function convertToBrasiliaDateTime(string $dateTimeString): DateTime {
    try {
        $dt = new DateTime($dateTimeString);
        $dt->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        return $dt;
    } catch (Exception $e) {
        // Se falhar, retorna data/hora atual de Brasília
        return getBrasiliaDateTime();
    }
}

/**
 * Formata uma data/hora para exibição no padrão brasileiro
 * @param string $dateTimeString
 * @param string $format (padrão: 'd/m/Y H:i')
 * @return string
 */
function formatBrasiliaDateTime(string $dateTimeString, string $format = 'd/m/Y H:i'): string {
    try {
        $dt = convertToBrasiliaDateTime($dateTimeString);
        return $dt->format($format);
    } catch (Exception $e) {
        return $dateTimeString;
    }
}
