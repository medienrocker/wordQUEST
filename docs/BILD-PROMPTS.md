# Bilder für wordQUEST selbst erzeugen

Eine kleine, sorgfältig gebaute Sammlung. Sie deckt genau die Wörter ab, bei
denen ein Bild wirklich hilft, und nicht mehr.

## Warum nur so wenige

Von 199 Wörtern im Bestand haben 82 Prozent bereits ein Emoji oder ein Bild.
Von den 35 ohne Visualisierung sind die meisten Funktionswörter wie „about",
„always" oder „of". Als Bild ergeben die bestenfalls Rätsel, und ein Rätsel
lenkt vom Lernen ab. Für sie ist der Beispielsatz die richtige Antwort, und
den haben inzwischen 86 Prozent aller Wörter.

Übrig bleiben rund fünfzehn Begriffe. Für die lohnt sich die Mühe.

## Der Hausstil

**Diesen Block vor jeden Einzelprompt setzen.** Er sorgt dafür, dass die
Bilder wie ein Satz wirken und nicht wie eine Sammlung.

```
flat vector illustration, single clear subject, centred composition,
plain light background (#f0faf0), soft rounded shapes, friendly and calm,
limited palette of green, teal, orange and warm grey, thick clean outlines,
no gradients, no shadows, no perspective, no scene, no background objects,
child-friendly, suitable for a school app, square format
```

### Was ausgeschlossen gehört

```
no text, no letters, no numbers, no words, no watermark, no signature,
no realistic faces, no recognisable people, no brand logos, no photo,
no 3D render, no clutter
```

**„no text" ist der wichtigste Punkt.** Bildmodelle schreiben gern Wörter mit,
und ein Bild, das „apple" beschriftet, verrät die Lösung. Bitte jedes fertige
Bild daraufhin ansehen, die Modelle halten sich nicht zuverlässig daran.

**„no recognisable people" ebenfalls nicht aus Ästhetik.** Kinder sollen sich
wiederfinden können, und das gelingt mit einer angedeuteten Figur ohne
Gesichtszüge besser als mit einem konkreten Gesicht, das eben doch aussieht
wie jemand Bestimmtes.

## Die Einzelprompts

Die schwierigen Fälle zuerst, denn dort steckt die eigentliche Arbeit: Ein
Verb oder ein Mengenwort lässt sich nur über eine **Gegenüberstellung**
darstellen. „viel" allein ist kein Bild, „ein grosser Haufen neben einem
kleinen" schon.

| Wort | Deutsch | Prompt (hinter den Hausstil setzen) |
|------|---------|--------------------------------------|
| `come` | kommen | `a simple faceless figure walking towards the viewer through an open door, arrow pointing forward` |
| `stay` | bleiben | `a simple faceless figure sitting calmly on a chair while two others walk away, anchor symbol` |
| `far` | weit | `two small houses at opposite edges of the image with a long dotted line between them` |
| `want` | wollen | `a faceless figure reaching upwards towards a glowing star just out of reach` |
| `a lot (of)` | viel | `a large pile of identical round objects next to a single one, clear size contrast` |
| `lots of` | jede Menge | `many identical small circles filling the frame, one circle set apart` |
| `have got` | haben | `two open hands holding a simple box, seen from the front` |
| `I've got` | ich habe | `a faceless figure pointing at itself with one hand while holding a small bag in the other` |
| `he` | er | `one faceless figure highlighted in teal, two greyed-out figures beside it, arrow pointing at the highlighted one` |
| `his` | sein | `a faceless figure and a ball connected by a short line, ownership shown by a simple bracket` |
| `them` | sie, ihnen | `a group of three faceless figures highlighted together in teal, arrow pointing at the group` |
| `(to) me` | mir, mich | `a faceless figure pointing at its own chest, arrow curving back towards it` |
| `always` | immer | `a circular arrow around a small calendar grid, every field ticked` |
| `again` | wieder | `a single circular arrow returning to its starting point, small dot marking the start` |
| `I haven't got` | ich habe nicht | `two empty open hands, a crossed-out box floating above them` |

### Wörter, bei denen ein Bild nicht lohnt

`about`, `also`, `and`, `at`, `be`, `but`, `have`, `it`, `not`, `of`, `really`,
`so`, `some`, `that`, `their`, `there's`, `these`, `they`, `to`, `too`, `all`,
`away`.

Für diese steht der Beispielsatz in der Vokabelliste, und der erklärt mehr,
als ein Bild es je könnte. Wer es trotzdem versucht, bekommt eine Illustration,
die das Kind erst entschlüsseln muss, bevor es lernen kann.

## Stand

14 der 15 Motive sind erzeugt und zugeordnet, sie liegen in `img/wq/`.
Ausgenommen ist `again`: Dort steht bereits das Emoji 🔁, und nach der Regel
oben schliessen Bilder nur Lücken, wo kein passendes Emoji existiert.

## So kommen die Bilder in die App

1. Bild erzeugen, am besten quadratisch. Die Grösse ist gleichgültig, der
   Server rechnet ohnehin um.
2. Im Admincenter unter **Bilder** die Wortliste wählen.
3. In der Arbeitsliste „Ohne Visualisierung" beim passenden Wort hochladen.

**Mehrere Motive in einem Bild?** Bildmodelle liefern oft ein Raster mit vier
Motiven auf einmal. Das ist günstig, muss aber vor dem Hochladen zerlegt
werden: ein Bild, ein Wort. Wichtig dabei ist nur, dass zwischen den Motiven
ein freier Streifen bleibt, dann ist der Schnitt eindeutig.

Der Server erzeugt daraus ein neues WebP mit höchstens 256 Pixel Kantenlänge.
Die hochgeladene Datei selbst wird nie ausgeliefert, sie wird neu gezeichnet,
und alles, was sonst noch in ihr steckte, verschwindet dabei. Deshalb ist auch
gleichgültig, welches Werkzeug das Bild erzeugt hat.

## Noch kurz zur Durchsicht

Bitte jedes Bild vor dem Hochladen ansehen und auf drei Dinge achten:

- **Steht Text im Bild?** Dann verwerfen. Auch einzelne Buchstaben zählen.
- **Ist das Motiv ohne die Vokabel erkennbar?** Wer das Bild ohne das Wort
  daneben nicht deuten kann, verwirrt damit auch das Kind.
- **Passt es zu den übrigen?** Ein Ausreisser im Stil fällt in der
  Vokabelliste sofort auf, weil dort alle Bilder untereinander stehen.

Verworfene Bilder einfach nicht hochladen. Das Wort fällt dann auf den
farbigen Kreis mit dem Anfangsbuchstaben zurück, und das ist kein Mangel,
sondern die vorgesehene Rückfallebene.
