' Dispara o worker de WhatsApp SEM abrir janela.
'
' O Agendador chamava o php.exe direto, e como ele é um programa de console o
' Windows abria um CMD por um instante — de minuto em minuto, o dia inteiro.
' Nem "-Hidden" nas opções da tarefa resolve: aquilo esconde a tarefa na lista,
' não a janela do processo.
'
' O wscript.exe não tem console próprio, e o terceiro argumento do Run (0) diz
' pro processo filho nascer com a janela oculta. Resultado: nada pisca na tela.
'
' O segundo argumento é True: o script ESPERA o worker terminar.
'
' Era False, e isso duplicava mensagem. O Agendador dava a tarefa por
' concluída no instante em que o vbs saía, e um minuto depois disparava de
' novo — com o PHP anterior ainda vivo, porque a rodada dura ~55s. Dois
' workers puxando a mesma fila mandam a mesma mensagem duas vezes.
'
' O "IgnoreNew" da tarefa não protegia nada nesse arranjo: ele impede uma
' segunda instância da TAREFA, e a tarefa já tinha terminado — quem seguia
' vivo era o processo neto, que o Agendador nem enxerga.
'
' Esperando, a tarefa só termina quando o worker termina, e aí o IgnoreNew
' passa a valer de verdade. A janela continua oculta (terceiro argumento 0).

Dim shell, fso, aqui, php, worker

Set shell = CreateObject("WScript.Shell")
Set fso   = CreateObject("Scripting.FileSystemObject")

aqui   = fso.GetParentFolderName(WScript.ScriptFullName)
worker = aqui & "\whatsapp-local.php"
php    = "C:\xampp\php\php.exe"

If Not fso.FileExists(php) Then
    ' Sem PHP no lugar esperado não há o que fazer — sair em silêncio, porque
    ' um MsgBox aqui viraria um popup por minuto, que é justamente o que
    ' estamos tirando da frente.
    WScript.Quit 1
End If

If Not fso.FileExists(worker) Then WScript.Quit 1

shell.Run """" & php & """ """ & worker & """", 0, True
