#!/usr/bin/env python3
"""Ask an X11 window to close the way a window manager's close button does: a WM_DELETE_WINDOW
client message (xdotool's `windowclose` destroys the window instead, which the toolkit treats as
a fatal X error). Standard library only (ctypes over libX11).

    x11_close.py <window id, decimal>
"""
import ctypes
import sys

x11 = ctypes.CDLL("libX11.so.6")
x11.XOpenDisplay.restype = ctypes.c_void_p
x11.XOpenDisplay.argtypes = [ctypes.c_char_p]
x11.XInternAtom.restype = ctypes.c_ulong
x11.XInternAtom.argtypes = [ctypes.c_void_p, ctypes.c_char_p, ctypes.c_int]
x11.XSendEvent.argtypes = [ctypes.c_void_p, ctypes.c_ulong, ctypes.c_int, ctypes.c_long, ctypes.c_void_p]
x11.XFlush.argtypes = [ctypes.c_void_p]
x11.XCloseDisplay.argtypes = [ctypes.c_void_p]


class ClientMessage(ctypes.Structure):
    _fields_ = [
        ("type", ctypes.c_int), ("serial", ctypes.c_ulong), ("send_event", ctypes.c_int),
        ("display", ctypes.c_void_p), ("window", ctypes.c_ulong), ("message_type", ctypes.c_ulong),
        ("format", ctypes.c_int), ("data", ctypes.c_long * 5),
    ]


class XEvent(ctypes.Union):
    _fields_ = [("client", ClientMessage), ("pad", ctypes.c_long * 24)]


def main() -> int:
    window = int(sys.argv[1])
    display = x11.XOpenDisplay(None)
    if not display:
        print("cannot open the X display", file=sys.stderr)
        return 1
    event = XEvent()
    event.client.type = 33  # ClientMessage
    event.client.window = window
    event.client.message_type = x11.XInternAtom(display, b"WM_PROTOCOLS", 0)
    event.client.format = 32
    event.client.data[0] = x11.XInternAtom(display, b"WM_DELETE_WINDOW", 0)
    event.client.data[1] = 0  # CurrentTime
    x11.XSendEvent(display, window, 0, 0, ctypes.byref(event))
    x11.XFlush(display)
    x11.XCloseDisplay(display)
    return 0


if __name__ == "__main__":
    sys.exit(main())
