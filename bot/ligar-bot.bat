@echo off
REM ═══════════════════════════════════════════════════════════════════════
REM  LIGAR O BOT AGORA — pra quando a tarefa agendada nao esta rodando.
REM
REM  A tarefa "FBA WhatsApp Worker" cobre todos os dias das 08:00 as 18:30.
REM  Fora disso (ou com o computador recem-ligado) o worker nao esta de pe,
REM  e nenhuma mensagem sai — nem comando, nem o que voce escrever no
REM  Painel do Bot.
REM
REM  Este arquivo mantem o worker vivo enquanto a janela estiver aberta.
REM  Feche a janela pra parar. Nao mexe na tarefa agendada.
REM
REM  IMPORTANTE: isto liga o TRANSPORTE, nao a janela de envio. Fora de
REM  08:45-18:00 continuam saindo so comando e mensagem manual. Pra liberar
REM  o resto, use o botao "liberar" no Painel do Bot.
REM ═══════════════════════════════════════════════════════════════════════

title FBA - Bot do WhatsApp (feche esta janela pra desligar)
cd /d "%~dp0"

echo.
echo   Bot do WhatsApp ligado. Feche esta janela pra desligar.
echo   Cada rodada dura ~55 segundos e o log vai pra whatsapp-local.log
echo.

:loop
C:\xampp\php\php.exe whatsapp-local.php
if errorlevel 1 (
  echo   [%time:~0,8%] a rodada terminou com erro - tentando de novo em 15s
  timeout /t 15 /nobreak >nul
) else (
  echo   [%time:~0,8%] rodada concluida
)
goto loop
