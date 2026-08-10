<#
    Cria a tarefa agendada que roda o worker de WhatsApp.

    Segunda a sexta, de minuto em minuto, das 08:00 às 18:30. A janela real
    (08:45–18:00) quem decide é o servidor — esta faixa é só pra não manter a
    tarefa acordada 24h à toa, com folga nas pontas.

    Rodar UMA vez, num PowerShell como Administrador:
        powershell -ExecutionPolicy Bypass -File bot\agendar-windows.ps1

    Para remover depois:
        Unregister-ScheduledTask -TaskName "FBA WhatsApp Worker" -Confirm:$false
#>

$ErrorActionPreference = 'Stop'

$nomeTarefa = 'FBA WhatsApp Worker'

# wscript + .vbs em vez de chamar o php.exe direto. O php e um programa de
# console: o Windows abria uma janela de CMD por um instante a cada minuto, o
# dia inteiro. O -Hidden das opcoes da tarefa NAO resolve isso — aquilo esconde
# a tarefa na lista do Agendador, nao a janela do processo. O wscript nao tem
# console proprio e o .vbs inicia o php ja oculto.
$php        = 'C:\Windows\System32\wscript.exe'
$script     = Join-Path (Split-Path -Parent $PSScriptRoot) 'bot\whatsapp-local.vbs'

if (-not (Test-Path $php))    { throw "wscript nao encontrado em $php" }
if (-not (Test-Path $script)) { throw "Wrapper nao encontrado em $script" }

$config = Join-Path (Split-Path -Parent $PSScriptRoot) 'bot\whatsapp-local.config.php'
if (-not (Test-Path $config)) {
    throw "Falta a configuracao. Rode antes:  php bot\configurar.php <token-do-site>"
}

# -WindowStyle Hidden nao existe em ScheduledTaskAction; quem esconde a janela
# e a opcao -Hidden do settings + rodar sem interacao.
$acao = New-ScheduledTaskAction -Execute $php -Argument "`"$script`"" `
                                -WorkingDirectory (Split-Path -Parent $script)

# Repeticao de 1 minuto ate o fim do dia, comecando 08:00, de segunda a sexta.
$gatilho = New-ScheduledTaskTrigger -Weekly `
    -DaysOfWeek Monday,Tuesday,Wednesday,Thursday,Friday -At 08:00
$gatilho.Repetition = (New-ScheduledTaskTrigger -Once -At 08:00 `
    -RepetitionInterval (New-TimeSpan -Minutes 1) `
    -RepetitionDuration (New-TimeSpan -Hours 10 -Minutes 30)).Repetition

$opcoes = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 10) `
    -Hidden

# Se a tarefa ja existir, recria: mais previsivel que tentar alterar em cima.
if (Get-ScheduledTask -TaskName $nomeTarefa -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName $nomeTarefa -Confirm:$false
    Write-Host "Tarefa anterior removida."
}

Register-ScheduledTask -TaskName $nomeTarefa -Action $acao -Trigger $gatilho `
    -Settings $opcoes -Description 'Envia a fila de WhatsApp da FBA pela Evolution local' | Out-Null

Write-Host ""
Write-Host "Tarefa '$nomeTarefa' criada."
Write-Host "  quando: seg-sex, 08:00 as 18:30, a cada minuto"
Write-Host "  janela real de envio: decidida pelo servidor (08:45-18:00)"
Write-Host "  log: bot\whatsapp-local.log"
Write-Host ""
Write-Host "Rodar agora pra testar:  Start-ScheduledTask -TaskName '$nomeTarefa'"
