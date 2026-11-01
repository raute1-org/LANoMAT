"""Tests for the Denon telnet sidecar.

Pure parsing and command building are tested directly. The REST surface uses
FastAPI's TestClient. One loopback test drives the telnet loop against a fake
receiver on 127.0.0.1 (an ephemeral port); nothing here reaches a real
receiver or the internet.
"""

import math
import socket
import threading
import time

import pytest
from fastapi.testclient import TestClient

import app as denon
from app import Bridge, ReceiverUnreachable, Receiver, build_command, format_volume, sanitize

TOKEN = "test-token"


# --- pure parsing -----------------------------------------------------------


def parse(*lines):
    receiver = Receiver()
    for line in lines:
        receiver.parse_line(line)
    return receiver.state


def test_power_lines():
    assert parse("PWON")["power"] is True
    assert parse("PWON", "PWSTANDBY")["power"] is False


def test_main_zone_lines():
    assert parse("ZMON")["mainZone"] is True
    assert parse("ZMOFF")["mainZone"] is False


def test_volume_half_step():
    assert parse("MV325")["volume"] == 32.5


def test_volume_whole_step():
    assert parse("MV05")["volume"] == 5


def test_volume_max():
    assert parse("MVMAX 98")["volumeMax"] == 98.0


def test_mute_lines():
    assert parse("MUON")["mute"] is True
    assert parse("MUON", "MUOFF")["mute"] is False


def test_source_line():
    assert parse("SIBD")["source"] == "BD"


def test_mode_line_is_titled():
    assert parse("MSSTEREO")["mode"] == "Stereo"


def test_quick_select_mode_line_is_ignored():
    assert parse("MSQUICK1")["mode"] == ""


def test_source_names_listed_when_ssfun_reports_them():
    state = parse("SSFUN BD Blu-ray")
    assert {"code": "BD", "name": "Blu-ray"} in state["sources"]


def test_default_input_names_are_listed_without_ssfun():
    codes = {s["code"]: s["name"] for s in Receiver().state["sources"]}
    assert codes["NET"] == "HEOS Music"
    assert codes["TUNER"] == "Tuner"


def test_ssfun_end_marker_changes_nothing():
    receiver = Receiver()
    before = receiver.state["sources"]
    assert receiver.parse_line("SSFUN END") is False
    assert receiver.state["sources"] == before


def test_parse_line_reports_change():
    receiver = Receiver()
    assert receiver.parse_line("PWON") is True
    assert receiver.parse_line("PWON") is False


def test_zone_two_lines_are_ignored():
    state = parse("Z2ON", "Z2MUON", "Z260", "Z2OFF")
    assert not any(key.startswith("zone2") for key in state)
    assert "zone2" not in state


def test_state_shape_has_no_zone_two_keys():
    state = Receiver().state
    assert set(state) == {
        "connected",
        "power",
        "mainZone",
        "volume",
        "volumeMax",
        "mute",
        "source",
        "mode",
        "sources",
        "modes",
    }
    assert state["connected"] is False
    assert state["volume"] is None
    assert state["volumeMax"] == 98.0
    assert [m["key"] for m in state["modes"]] == [
        "stereo",
        "direct",
        "pure",
        "auto",
        "movie",
        "music",
        "game",
    ]


def test_hostile_source_name_is_sanitised():
    state = parse("SSFUN BD <b>x</b>" + "a" * 50)
    name = next(s["name"] for s in state["sources"] if s["code"] == "BD")
    assert "<" not in name and ">" not in name
    assert len(name) <= 32


# --- helpers ----------------------------------------------------------------


@pytest.mark.parametrize(
    "value, expected",
    [(32.5, "MV325"), (5, "MV05"), (120, "MV98"), (-3, "MV00"), (32.3, "MV325"), (32.7, "MV325")],
)
def test_format_volume(value, expected):
    assert format_volume(value) == expected


def test_sanitize_strips_markup_and_limits_length():
    text = sanitize("<b>x</b>" + "a" * 50)
    assert "<" not in text and ">" not in text
    assert len(text) <= 32


def test_sanitize_drops_quotes_backticks_and_ampersands():
    assert sanitize("a&b\"c'd`e") == "abcde"


# --- command building -------------------------------------------------------


@pytest.mark.parametrize(
    "command, value, telnet",
    [
        ("power", "on", "PWON"),
        ("power", "off", "PWSTANDBY"),
        ("volume", 32.5, "MV325"),
        ("volume", 0, "MV00"),
        ("volume", 98, "MV98"),
        ("mute", "on", "MUON"),
        ("mute", "off", "MUOFF"),
        ("source", "BD", "SIBD"),
        ("source", "NET", "SINET"),
        ("source", "AUX1/2", "SIAUX1/2"),
        ("mode", "stereo", "MSSTEREO"),
        ("mode", "pure", "MSPURE DIRECT"),
    ],
)
def test_build_command_whitelisted(command, value, telnet):
    assert build_command(command, value) == telnet


@pytest.mark.parametrize(
    "command, value",
    [
        ("zone", "2 on"),
        ("refresh", None),
        ("raw", "PWON"),
        ("", "on"),
        ("power", "toggle"),
        ("power", True),
        ("volume", 99),
        ("volume", -1),
        ("volume", "32"),
        ("volume", True),
        ("volume", math.nan),
        ("volume", math.inf),
        ("mute", "toggle"),
        ("mute", None),
        ("source", "bad code"),
        ("source", "x" * 13),
        ("source", 5),
        ("mode", "nope"),
        ("mode", None),
    ],
)
def test_build_command_rejects(command, value):
    with pytest.raises(ValueError):
        build_command(command, value)


def test_unknown_command_raises_value_error():
    with pytest.raises(ValueError):
        build_command("zone2", "on")


# --- HTTP surface -----------------------------------------------------------


class FakeBridge:
    """Stands in for the telnet bridge: records what would be sent to the receiver."""

    def __init__(self):
        self.sent = []

    @property
    def connected(self):
        return True

    def snapshot(self):
        return Receiver().state

    def enqueue(self, command):
        self.sent.append(command)


@pytest.fixture
def auth(monkeypatch):
    monkeypatch.setattr(denon, "ADMIN_TOKEN", TOKEN)
    return {"Authorization": f"Bearer {TOKEN}"}


@pytest.fixture
def client():
    return TestClient(denon.app)


@pytest.fixture
def fake_bridge(monkeypatch):
    fake = FakeBridge()
    monkeypatch.setattr(denon, "bridge", fake)
    return fake


def test_healthz_needs_no_auth(client):
    response = client.get("/healthz")
    assert response.status_code == 200
    assert response.json() == {"status": "ok", "connected": False}


def test_state_requires_token(client, auth):
    assert client.get("/state").status_code == 401
    assert client.get("/state", headers={"Authorization": "Bearer wrong"}).status_code == 401


def test_state_refused_when_token_not_configured(client, monkeypatch):
    monkeypatch.setattr(denon, "ADMIN_TOKEN", "")
    response = client.get("/state", headers={"Authorization": "Bearer "})
    assert response.status_code == 503


def test_state_returns_shape(client, auth, fake_bridge):
    response = client.get("/state", headers=auth)
    assert response.status_code == 200
    assert "zone2" not in response.json()
    assert response.json()["volumeMax"] == 98.0


def test_command_requires_token(client, auth, fake_bridge):
    response = client.post("/command", json={"command": "power", "value": "on"})
    assert response.status_code == 401
    assert fake_bridge.sent == []


def test_command_unknown_is_422(client, auth, fake_bridge):
    response = client.post("/command", json={"command": "zone", "value": "2 on"}, headers=auth)
    assert response.status_code == 422
    assert fake_bridge.sent == []


def test_command_volume_out_of_range_is_422(client, auth, fake_bridge):
    response = client.post("/command", json={"command": "volume", "value": 99}, headers=auth)
    assert response.status_code == 422
    assert fake_bridge.sent == []


def test_command_not_connected_is_503(client, auth):
    # The real bridge is never started in tests, so it is not connected.
    response = client.post("/command", json={"command": "power", "value": "on"}, headers=auth)
    assert response.status_code == 503


def test_command_accepted_is_200(client, auth, fake_bridge):
    response = client.post(
        "/command", json={"command": "volume", "value": 32.5}, headers=auth
    )
    assert response.status_code == 200
    assert response.json() == {"ok": True}
    assert fake_bridge.sent == ["MV325"]


def test_real_bridge_refuses_commands_when_disconnected():
    bridge = Bridge(host="")
    with pytest.raises(ReceiverUnreachable):
        bridge.enqueue("PWON")


# --- telnet loop against a loopback fake receiver ---------------------------


def _read_until(conn, marker, timeout=5.0):
    conn.settimeout(timeout)
    data = b""
    deadline = time.monotonic() + timeout
    while marker not in data:
        if time.monotonic() > deadline:
            raise AssertionError(f"marker {marker!r} not seen, got {data!r}")
        data += conn.recv(4096)
    return data


def _wait_for(predicate, timeout=5.0):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if predicate():
            return
        time.sleep(0.02)
    raise AssertionError("condition not reached in time")


def test_bridge_round_trip_against_loopback_receiver():
    server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    server.bind(("127.0.0.1", 0))
    server.listen(1)
    port = server.getsockname()[1]
    bridge = Bridge(host="127.0.0.1", port=port)
    bridge.start()
    try:
        conn, _ = server.accept()
        with conn:
            # Connect-time queries arrive first.
            _read_until(conn, b"SSFUN ?\r")
            _wait_for(lambda: bridge.connected)

            conn.sendall(b"PWON\rMV325\rSIBD\r")
            _wait_for(lambda: bridge.snapshot()["volume"] == 32.5)
            state = bridge.snapshot()
            assert state["power"] is True
            assert state["source"] == "BD"
            assert state["connected"] is True

            bridge.enqueue("MV330")
            _read_until(conn, b"MV330\r")
    finally:
        bridge.stop()
        server.close()
