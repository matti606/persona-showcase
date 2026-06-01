# Persona Tool – AI-gestuurde persoonlijkheidsmatching

Dit project is een AI-gestuurde persona tool die ik ontwikkeld heb tijdens mijn stage.
Op basis van een stellingentest (gebruikers kiezen telkens tussen twee stellingen) wordt via OpenAI een persoonlijkheidsprofiel opgesteld en automatisch gematcht met de best passende persona.

> **Over de codestructuur:** het stagebedrijf werkte met een eigen custom PHP CMS met een vaste werkwijze: logica leeft in losse script-bestanden, één centrale JS-file per pagina (geen bundler), een eigen autoloader en een afwijkende MVC-aanpak. Die patronen zijn eigen aan hun systeem en heb ik gevolgd om consistent te blijven met de bestaande codebase, ze weerspiegelen niet mijn eigen voorkeur voor architectuur.

---

## Hoe het werkt

### 1. Stellingentest
De gebruiker doorloopt een reeks stellingen en kiest telkens één van twee opties (A of B).

### 2. AI trait-extractie (`persona_engine.php`)
Zodra de gebruiker genoeg stellingen heeft beantwoord, worden de antwoorden naar OpenAI's GPT-4o gestuurd via de [openai-php/client](https://github.com/openai-php/client) library.

De prompt stuurt GPT-4o om de antwoorden te interpreteren en er persoonlijkheidskenmerken (traits) uit te destilleren, elk met een score van 0–100. De response is strikt gestructureerd via een **JSON Schema** (structured output), zodat de data direct bruikbaar is zonder verdere parsing.

```php
$result = $client->chat()->create([
    'model' => 'gpt-4o',
    'messages' => $input,
    'response_format' => [
        'type' => 'json_schema',
        'json_schema' => $schema
    ],
]);
```

De traits worden daarna opgeslagen in de database, gekoppeld aan de gebruiker.

### 3. Persona matching (`PersonaMatcher.php`)
Het matching-algoritme vergelijkt de traits van de gebruiker (gesorteerd op score) met de traits van alle beschikbare persona's.

Het scoringssysteem houdt rekening met:
- **Volgorde-match**: een trait die op dezelfde positie staat als bij de persona krijgt meer punten
- **Aanwezigheid**: een trait die wel aanwezig is maar op een andere positie staat, krijgt minder punten, afhankelijk van hoe ver die positie afwijkt

```php
private function compare(array $userTraits, array $personaTraits): int
{
    $score = 0;
    $max = min(count($userTraits), count($personaTraits));

    for ($i = 0; $i < $max; $i++) {
        if ($userTraits[$i] === $personaTraits[$i]) {
            // exacte positiematch: hoogste score
            $score += ($max * 2) - $i;
        } elseif (in_array($userTraits[$i], $personaTraits)) {
            // trait aanwezig maar op andere positie
            $personaTraitRanking = array_search($userTraits[$i], $personaTraits);
            $score += $max - $personaTraitRanking;
        }
    }

    return $score;
}
```

De top-5 best scorende persona's worden teruggegeven, gesorteerd op score.

### 4. Slim triggeren
Het systeem herberekent de traits en persona enkel wanneer nodig:
- De gebruiker heeft genoeg stellingen beantwoord (drempelwaarde instelbaar)
- De gebruiker heeft zijn antwoorden gewijzigd sinds de laatste berekening
- De gebruiker heeft nog geen persona toegewezen gekregen

```php
if (
    $personaUnlockedWithoutTraits
    || $personaUnlocked && $client->hasChangedStatementsCount()
    || $personaUnlocked && !$client->personaID
) {
    getPersonalityTraits($site, $client);
    matchPersona($client, $personaModel);
}
```

---

## Bestanden

| Bestand | Beschrijving |
|---|---|
| `persona_engine.php` | Haalt stellingen op, roept OpenAI aan, slaat traits op, triggert matching |
| `PersonaMatcher.php` | Matching-algoritme: vergelijkt gebruikersprofiel met alle persona's |

---

## Stack
- PHP
- OpenAI API (GPT-4o, structured output via JSON Schema)
- MySQL (via interne CRM-laag van het stagebedrijf)
