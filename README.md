# Annex Diff Viewer — WordPress Plugin

Side-by-side porównanie Załącznika I dyrektywy 2006/42/WE z Załącznikiem III rozporządzenia (EU) 2023/1230.
VS Code–style diff: wyrównane sekcje, zaznaczone zmiany, zsynchronizowane przewijanie, renderowanie Markdown.

---

## Instalacja

1. Skopiuj folder `annex-diff/` do katalogu `/wp-content/plugins/`
2. Upewnij się że folder `data/` zawiera oba pliki `.md`:
   ```
   annex-diff/
   ├── annex-diff.php
   ├── README.md
   └── data/
       ├── ANNEX_I_2006-42-WE.md
       └── ANNEX_III_2023-1230.md
   ```
3. Aktywuj plugin w panelu WP (Wtyczki → Zainstalowane)
4. Utwórz nową stronę i wstaw shortcode:
   ```
   [annex_diff]
   ```
5. Strona powinna być ustawiona na **pełną szerokość** (Full Width template) — plugin automatycznie próbuje to wymusić przez CSS, ale w niektórych motywach może być potrzebne ręczne ustawienie.

---

## Shortcode — opcje

```
[annex_diff left="ANNEX_I_2006-42-WE.md" right="ANNEX_III_2023-1230.md"]
```

Parametry `left` i `right` to nazwy plików z folderu `data/`. Domyślne wartości wskazują na dostarczone pliki.

---

## Funkcje (v1.0)

| Funkcja | Opis |
|---|---|
| Sekcje wyrównane | Każda sekcja po lewej i prawej ma tę samą wysokość — nagłówki się nie "rozjeżdżają" |
| Word-level diff | W zmienionych sekcjach podświetlone są konkretne dodane/usunięte słowa |
| Markdown rendering | Nagłówki, listy, pogrubienia, kod — w pełni renderowane |
| Sync scroll | Przewijanie jednego panelu automatycznie synchronizuje drugi |
| Statystyki | Pasek górny pokazuje liczbę sekcji usuniętych / dodanych / zmienionych |
| VS Code dark theme | Ciemny motyw, Fira Code, kolorystyka zgodna z VS Code |

---

## Planowane rozszerzenia (v2.0+)

- [ ] Przełącznik EN ↔ PL (polskie tłumaczenie obu anneksów)
- [ ] Chmurki (popovers) z polskim tłumaczeniem akapitu
- [ ] Chmurki z podpowiedziami / referencjami do norm
- [ ] Filtr: pokaż tylko sekcje zmienione / dodane / usunięte
- [ ] Eksport diff do PDF
- [ ] Wyszukiwarka w treści

---

## Wymagania

- WordPress 5.5+
- PHP 7.4+
- Nowoczesna przeglądarka (Chrome / Firefox / Edge / Safari)
- Dostęp do CDN (ładuje `marked.js` z jsDelivr)

Jeśli CDN jest zablokowany, możesz zamiast tego umieścić `marked.min.js` lokalnie w folderze pluginu i zmienić src w kodzie na lokalny URL.
