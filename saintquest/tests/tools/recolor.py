"""Build per-saint palette textures from the KayKit model textures (CC0).

python3 saintquest/tests/tools/recolor.py <kaykit Characters/gltf dir>
Writes assets/models/tex/<key>.png and assets/data/saints.json.
"""
import colorsys, json, os, sys
from PIL import Image

here = os.path.dirname(os.path.abspath(__file__))
root = os.path.abspath(os.path.join(here, '..', '..'))
src_dir = sys.argv[1]
tex_name = {'knight': 'knight', 'mage': 'mage', 'rogue-hooded': 'rogue', 'rogue': 'rogue', 'ranger': 'ranger', 'barbarian': 'barbarian'}
saints = json.load(open(os.path.join(here, 'saints.src.json')))
os.makedirs(os.path.join(root, 'assets/models/tex'), exist_ok=True)

def in_band(h, band):
    a, b = band
    return (a <= h <= b) if a <= b else (h >= a or h <= b)

out = []
for s in saints:
    rules = s.pop('rules')
    entry = dict(s)
    if rules:
        im = Image.open(os.path.join(src_dir, tex_name[s['model']] + '_texture.png')).convert('RGBA').resize((256, 256), Image.LANCZOS)
        px = im.load()
        for y in range(im.height):
            for x in range(im.width):
                r, g, b, a = px[x, y]
                h, sat, v = colorsys.rgb_to_hsv(r / 255, g / 255, b / 255)
                hd = h * 360
                for rule in rules:
                    if rule.get('gray'):
                        hit = sat < 0.14 and 0.22 < v < 0.9
                    else:
                        hit = sat >= rule.get('minSat', 0.18) and in_band(hd, rule['from'])
                    if hit:
                        ns = min(1, max(sat, 0.35) * rule.get('sat', 1)) if not rule.get('gray') else rule.get('sat', 0.6)
                        nv = min(1, v * rule.get('val', 1))
                        nr, ng, nb = colorsys.hsv_to_rgb((rule['to'] % 360) / 360, ns, nv)
                        px[x, y] = (int(nr * 255), int(ng * 255), int(nb * 255), a)
                        break
        name = s['key'] + '.png'
        im.save(os.path.join(root, 'assets/models/tex', name), optimize=True)
        entry['tex'] = name
    else:
        entry['tex'] = None
    out.append(entry)

json.dump({'version': 1, 'source': 'KayKit Adventurers 2.0 by Kay Lousberg (CC0)', 'saints': out},
          open(os.path.join(root, 'assets/data/saints.json'), 'w'), indent=1)
print(len(out), 'saints')
