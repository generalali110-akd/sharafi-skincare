#!/usr/bin/env python3

import argparse
import json
import math
import sys
from pathlib import Path

from PIL import Image


def dhash(image: Image.Image, hash_size: int = 8) -> str:
    gray = image.convert('L').resize((hash_size + 1, hash_size), Image.Resampling.LANCZOS)
    pixels = list(gray.getdata())
    value = 0
    row_width = hash_size + 1

    for y in range(hash_size):
        row = pixels[y * row_width:(y + 1) * row_width]
        for x in range(hash_size):
            value = (value << 1) | int(row[x] > row[x + 1])

    return f'{value:016x}'


def fingerprint(path: Path) -> dict:
    with Image.open(path) as source:
        image = source.convert('RGB')
        width, height = image.size
        grid = image.resize((8, 8), Image.Resampling.LANCZOS)
        grid_rgb = [channel for pixel in grid.getdata() for channel in pixel]
        bands_rgb = []
        hashes = {'full': dhash(image)}

        for index in range(8):
            y0 = round(height * index / 8)
            y1 = round(height * (index + 1) / 8)
            band = image.crop((0, y0, width, y1)).resize((1, 1), Image.Resampling.BOX)
            bands_rgb.extend(band.getpixel((0, 0)))

        for index in range(4):
            y0 = round(height * index / 4)
            y1 = round(height * (index + 1) / 4)
            hashes[f'q{index + 1}'] = dhash(image.crop((0, y0, width, y1)))

    return {
        'width': width,
        'height': height,
        'grid8_rgb': grid_rgb,
        'bands8_rgb': bands_rgb,
        'dhash': hashes,
    }


def rmse(left: list[int], right: list[int]) -> float:
    if len(left) != len(right) or not left:
        return math.inf
    return math.sqrt(sum((a - b) ** 2 for a, b in zip(left, right)) / len(left))


def hamming(left: str, right: str) -> int:
    return (int(left, 16) ^ int(right, 16)).bit_count()


def compare(name: str, expected: dict, actual: dict) -> list[str]:
    failures = []

    if actual['width'] != expected['width']:
        failures.append(f"width {actual['width']} != {expected['width']}")

    height_delta = abs(actual['height'] - expected['height'])
    height_limit = max(4, round(expected['height'] * 0.005))
    if height_delta > height_limit:
        failures.append(
            f"height delta {height_delta}px exceeds {height_limit}px "
            f"({actual['height']} vs {expected['height']})"
        )

    grid_error = rmse(expected['grid8_rgb'], actual['grid8_rgb'])
    if grid_error > 12:
        failures.append(f'8x8 color-grid RMSE {grid_error:.2f} exceeds 12')

    band_error = rmse(expected['bands8_rgb'], actual['bands8_rgb'])
    if band_error > 10:
        failures.append(f'vertical-band RMSE {band_error:.2f} exceeds 10')

    hash_deltas = {
        key: hamming(expected['dhash'][key], actual['dhash'][key])
        for key in expected['dhash']
    }
    if hash_deltas['full'] > 12:
        failures.append(f"full perceptual hash delta {hash_deltas['full']} exceeds 12")
    if sum(hash_deltas.values()) > 45:
        failures.append(f"regional perceptual hash delta {sum(hash_deltas.values())} exceeds 45")

    if not failures:
        print(
            f"[OK] {name}: height Δ{height_delta}px, grid {grid_error:.2f}, "
            f"bands {band_error:.2f}, hash {sum(hash_deltas.values())}"
        )

    return failures


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('--baseline', required=True, type=Path)
    parser.add_argument('--current-dir', required=True, type=Path)
    args = parser.parse_args()

    baseline = json.loads(args.baseline.read_text(encoding='utf-8'))
    failures = []

    for name, expected in baseline['baselines'].items():
        screenshot = args.current_dir / f'{name}.png'
        if not screenshot.is_file():
            failures.append(f'{name}: screenshot missing at {screenshot}')
            continue

        actual = fingerprint(screenshot)
        for failure in compare(name, expected, actual):
            failures.append(f'{name}: {failure}')

    unexpected = sorted(
        path.stem for path in args.current_dir.glob('*.png')
        if path.stem not in baseline['baselines']
    )
    for name in unexpected:
        failures.append(f'{name}: screenshot has no approved baseline')

    if failures:
        print('\nVisual regression failures:', file=sys.stderr)
        for failure in failures:
            print(f'  - {failure}', file=sys.stderr)
        return 1

    return 0


if __name__ == '__main__':
    raise SystemExit(main())
