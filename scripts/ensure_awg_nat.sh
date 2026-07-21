#!/usr/bin/env bash
# Restore WireGuard NAT/forwarding inside amnezia-awg (idempotent).
# Run on the VPS host (root cron). Safe to run every few minutes.
set -uo pipefail

CONTAINER="${AWG_CONTAINER_NAME:-amnezia-awg}"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"
}

if ! command -v docker >/dev/null 2>&1; then
    log "docker not found"
    exit 0
fi

if ! docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
    log "Container '$CONTAINER' is not running, skip"
    exit 0
fi

if [ "$(id -u)" -eq 0 ]; then
    sysctl -w net.ipv4.ip_forward=1 >/dev/null 2>&1 || true
    sysctl -w net.ipv4.conf.all.rp_filter=0 >/dev/null 2>&1 || true
    sysctl -w net.ipv4.conf.default.rp_filter=0 >/dev/null 2>&1 || true
fi

if ! docker exec "$CONTAINER" sh -s <<'EOS'
set -u

apply_nat() {
    echo 1 > /proc/sys/net/ipv4/ip_forward 2>/dev/null || true

    if [ ! -f /opt/amnezia/awg/wg0.conf ]; then
        echo "wg0.conf missing"
        exit 1
    fi

    if ! wg show wg0 >/dev/null 2>&1; then
        wg-quick up /opt/amnezia/awg/wg0.conf 2>/dev/null || true
    fi

    VPN_SUBNET="$(grep -m1 '^Address' /opt/amnezia/awg/wg0.conf | cut -d= -f2 | tr -d ' ')"
    if [ -z "$VPN_SUBNET" ]; then
        VPN_SUBNET="10.8.1.0/24"
    fi

    OUT_IF="$(ip route show default 2>/dev/null | awk '{print $5}' | head -n1)"
    if [ -z "$OUT_IF" ]; then
        OUT_IF="eth0"
    fi

    if iptables -t nat -L POSTROUTING -n >/dev/null 2>&1; then
        IPT="iptables"
    elif iptables-legacy -t nat -L POSTROUTING -n >/dev/null 2>&1; then
        IPT="iptables-legacy"
    else
        echo "iptables nat table unavailable"
        exit 1
    fi

    $IPT -C INPUT -i wg0 -j ACCEPT 2>/dev/null || $IPT -A INPUT -i wg0 -j ACCEPT
    $IPT -C FORWARD -i wg0 -j ACCEPT 2>/dev/null || $IPT -A FORWARD -i wg0 -j ACCEPT
    $IPT -C OUTPUT -o wg0 -j ACCEPT 2>/dev/null || $IPT -A OUTPUT -o wg0 -j ACCEPT

    $IPT -C FORWARD -i wg0 -o "$OUT_IF" -s "$VPN_SUBNET" -j ACCEPT 2>/dev/null || \
        $IPT -A FORWARD -i wg0 -o "$OUT_IF" -s "$VPN_SUBNET" -j ACCEPT

    $IPT -C FORWARD -i "$OUT_IF" -o wg0 -m state --state RELATED,ESTABLISHED -j ACCEPT 2>/dev/null || \
        $IPT -A FORWARD -i "$OUT_IF" -o wg0 -m state --state RELATED,ESTABLISHED -j ACCEPT

    $IPT -t nat -C POSTROUTING -s "$VPN_SUBNET" -o "$OUT_IF" -j MASQUERADE 2>/dev/null || \
        $IPT -t nat -A POSTROUTING -s "$VPN_SUBNET" -o "$OUT_IF" -j MASQUERADE

    if $IPT -t nat -C POSTROUTING -s "$VPN_SUBNET" -o "$OUT_IF" -j MASQUERADE 2>/dev/null; then
        echo "NAT OK ($VPN_SUBNET -> $OUT_IF via $IPT)"
    else
        echo "NAT rule missing after apply"
        exit 1
    fi
}

apply_nat
EOS
then
    log "Failed to apply NAT in container '$CONTAINER'"
    exit 1
fi

log "NAT ensured for container '$CONTAINER'"
exit 0
