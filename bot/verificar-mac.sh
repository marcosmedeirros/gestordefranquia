#!/bin/bash
#
# Confere se o bot está de pé no Mac. Roda quando desconfiar que parou:
#     bash bot/verificar-mac.sh
#
# Testa a corrente inteira, na ordem em que ela quebra na prática:
# suspensão → login automático → serviço → Evolution → site → fila.

AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LABEL="com.fba.whatsapp"
ok(){ printf "  \033[32m✓\033[0m %s\n" "$1"; }
ruim(){ printf "  \033[31m✗\033[0m %s\n" "$1"; }
aviso(){ printf "  \033[33m!\033[0m %s\n" "$1"; }

echo "── Estado do bot de WhatsApp ──"
echo

# 1. Suspensão — a causa número um
SLEEP="$(pmset -g custom 2>/dev/null | awk '/^ *sleep/{print $2; exit}')"
if [ "${SLEEP:-1}" = "0" ]; then ok "o Mac não suspende"
else ruim "o Mac suspende depois de ${SLEEP}min — o bot para junto (sudo pmset -a sleep 0)"; fi

# 2. Login automático — decide se volta depois de queda de energia
AUTO="$(sudo -n defaults read /Library/Preferences/com.apple.loginwindow autoLoginUser 2>/dev/null || true)"
if [ -n "$AUTO" ]; then ok "login automático ligado ($AUTO) — volta sozinho depois de reiniciar"
else aviso "login automático não confirmado. Sem ele, depois de queda de energia o Mac para na tela de senha e o bot não sobe"; fi

# 3. O serviço
if launchctl print "gui/$(id -u)/$LABEL" >/dev/null 2>&1; then
    PID="$(launchctl print "gui/$(id -u)/$LABEL" 2>/dev/null | awk '/^\tpid = /{print $3}')"
    [ -n "$PID" ] && ok "worker rodando (pid $PID)" || ok "worker carregado (entre execuções)"
else
    ruim "o worker NÃO está no launchd — rode: bash bot/instalar-mac.sh"
fi

# 4. Evolution
if curl -s --max-time 5 http://localhost:8081/ | grep -q "Evolution"; then
    ok "Evolution respondendo em localhost:8081"
    EST="$(curl -s --max-time 8 -H "apikey: ${EVO_API_KEY:-}" http://localhost:8081/instance/fetchInstances 2>/dev/null | grep -o '"connectionStatus":"[a-z]*"' | head -1 | cut -d'"' -f4)"
    case "$EST" in
      open) ok "WhatsApp conectado" ;;
      "")   aviso "não consegui ler o estado da instância (defina EVO_API_KEY pra ver)" ;;
      *)    ruim "WhatsApp em estado '$EST' — precisa parear de novo em http://localhost:8081/manager" ;;
    esac
else
    ruim "Evolution fora do ar — rode: cd bot/evolution && docker compose up -d"
fi

# 5. O site, com o token da própria configuração
CFG="$AQUI/whatsapp-local.config.php"
if [ -f "$CFG" ] && command -v php >/dev/null; then
    SITE="$(php -r '$c=require $argv[1]; echo rtrim($c["site_url"],"/");' "$CFG" 2>/dev/null)"
    TOKEN="$(php -r '$c=require $argv[1]; echo $c["bot_token"];' "$CFG" 2>/dev/null)"
    D="$(curl -s --max-time 15 -H "Authorization: Bearer $TOKEN" "$SITE/api/whatsapp-bot.php?action=diagnostico" 2>/dev/null)"
    if echo "$D" | grep -q '"ativo"'; then
        echo "$D" | php -r '
          $j=json_decode(stream_get_contents(STDIN),true);
          $v=fn($b)=>$b?"\033[32m✓\033[0m":"\033[31m✗\033[0m";
          printf("  %s bot ligado no painel\n", $v($j["ativo"]??false));
          printf("  \033[32m✓\033[0m %d grupos aceitando comando\n", $j["grupos_de_comando"]??0);
          $p=$j["fila"]["pendentes"]??0;
          printf("  %s fila: %d pendente(s)%s\n", $p>5?"\033[33m!\033[0m":"\033[32m✓\033[0m", $p, $p>5?" — algo travou":"");
          if (!empty($j["ultimo_erro"])) printf("  \033[33m!\033[0m ultimo erro: %s\n", $j["ultimo_erro"]);
          printf("  \033[32m✓\033[0m worker visto em %s\n", $j["bot_visto_em"] ?? "NUNCA");'
    else
        ruim "o site não respondeu ao diagnóstico — confira site_url e bot_token na configuração"
    fi
else
    aviso "sem configuração ou sem php: pulei a checagem do site"
fi

echo
echo "  Log do worker:  tail -20 $AQUI/whatsapp-local.log"
