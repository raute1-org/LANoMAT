import { useCallback, useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import type { ImgHTMLAttributes, MouseEvent } from "react";
import screens from "../scripts/screens.json";

type ScreenshotMeta = { width: number; height: number };
const manifest = screens as Record<string, ScreenshotMeta>;

const BASE_PATH = "/docs";

/** Zudoku's markdown images use root-relative paths; the docs live under /docs. */
function withBasePath(src: string): string {
    if (/^(https?:)?\/\//.test(src) || src.startsWith("data:")) {
        return src;
    }

    const path = src.startsWith("/") ? src : `/${src}`;

    return path.startsWith(`${BASE_PATH}/`) ? path : `${BASE_PATH}${path}`;
}

function manifestKey(src: string): string {
    return src
        .replace(/^\/?docs\//, "")
        .replace(/^\/?screens\//, "")
        .replace(/^\/+/, "");
}

type GalleryItem = { src: string; alt: string };

type Props = ImgHTMLAttributes<HTMLImageElement> & { node?: unknown };

/**
 * Every documentation screenshot is zoomable: clicking one opens a full-screen
 * view with the other screenshots of the page, keyboard navigation (←/→, Esc)
 * and a focus-managed dialog. Width/height come from scripts/screens.json so
 * the page does not jump while images load.
 */
export function ZoomableImage({ node: _node, src, alt, width, height, ...props }: Props) {
    const ref = useRef<HTMLImageElement>(null);
    const closeButton = useRef<HTMLButtonElement>(null);
    const previousFocus = useRef<Element | null>(null);
    const [items, setItems] = useState<GalleryItem[]>([]);
    const [index, setIndex] = useState(-1);

    const meta = src ? manifest[manifestKey(src)] : undefined;

    const close = useCallback(() => {
        setIndex(-1);
        setItems([]);

        if (previousFocus.current instanceof HTMLElement) {
            previousFocus.current.focus();
        }
    }, []);

    const step = useCallback((delta: number) => {
        setIndex((current) => {
            if (items.length === 0) {
                return current;
            }

            return (current + delta + items.length) % items.length;
        });
    }, [items.length]);

    const open = (event: MouseEvent<HTMLImageElement>) => {
        const image = event.currentTarget;
        const gallery = Array.from(
            document.querySelectorAll<HTMLImageElement>("img[data-zoomable]"),
        )
            .map((element) => ({
                src: element.currentSrc || element.src,
                alt: element.alt,
            }))
            .filter((item) => item.src !== "");

        const position = gallery.findIndex(
            (item) => item.src === (image.currentSrc || image.src) && item.alt === image.alt,
        );

        event.preventDefault();
        previousFocus.current = document.activeElement;
        setItems(gallery);
        setIndex(position >= 0 ? position : 0);
    };

    useEffect(() => {
        if (index < 0) {
            return;
        }

        closeButton.current?.focus();

        const onKeyDown = (keyboard: KeyboardEvent) => {
            if (keyboard.key === "Escape") {
                keyboard.preventDefault();
                close();
            } else if (keyboard.key === "ArrowRight") {
                keyboard.preventDefault();
                step(1);
            } else if (keyboard.key === "ArrowLeft") {
                keyboard.preventDefault();
                step(-1);
            }
        };

        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = "hidden";
        document.addEventListener("keydown", onKeyDown);

        return () => {
            document.body.style.overflow = previousOverflow;
            document.removeEventListener("keydown", onKeyDown);
        };
    }, [index, close, step]);

    if (!src) {
        return null;
    }

    const current = index >= 0 ? items[index] : undefined;

    return (
        <>
            <img
                {...props}
                ref={ref}
                src={withBasePath(src)}
                alt={alt ?? ""}
                width={width ?? meta?.width}
                height={height ?? meta?.height}
                loading={props.loading ?? "lazy"}
                decoding="async"
                data-zoomable="true"
                className={["shot-zoomable", props.className].filter(Boolean).join(" ")}
                onClick={open}
            />
            {current &&
                createPortal(
                    <div
                        className="shot-zoom"
                        role="dialog"
                        aria-modal="true"
                        aria-label="Screenshot"
                        onClick={close}
                    >
                        <button
                            ref={closeButton}
                            type="button"
                            className="shot-zoom-close"
                            aria-label="Close screenshot"
                            onClick={close}
                        >
                            ×
                        </button>

                        {items.length > 1 && (
                            <button
                                type="button"
                                className="shot-zoom-nav shot-zoom-prev"
                                aria-label="Previous screenshot"
                                onClick={(event) => {
                                    event.stopPropagation();
                                    step(-1);
                                }}
                            >
                                ‹
                            </button>
                        )}

                        <figure className="shot-zoom-figure" onClick={(event) => event.stopPropagation()}>
                            <img src={current.src} alt={current.alt} decoding="async" />
                            <figcaption>
                                <span>{current.alt}</span>
                                {items.length > 1 && (
                                    <span className="shot-zoom-count">
                                        {index + 1} / {items.length}
                                    </span>
                                )}
                            </figcaption>
                        </figure>

                        {items.length > 1 && (
                            <button
                                type="button"
                                className="shot-zoom-nav shot-zoom-next"
                                aria-label="Next screenshot"
                                onClick={(event) => {
                                    event.stopPropagation();
                                    step(1);
                                }}
                            >
                                ›
                            </button>
                        )}
                    </div>,
                    document.body,
                )}
        </>
    );
}

export default ZoomableImage;
