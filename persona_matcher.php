<?php

class PersonaMatcher
{
	private array $userTraits;
	private array $personas;

	public function __construct(array $userTraits, array $personas)
	{
		$this->parseData($userTraits, $personas);
	}

	private function parseData(array $userTraits, array $personas): void
	{
		// User traits parser
		usort($userTraits, fn($a, $b) => $b->score <=> $a->score);
		$this->userTraits = array_values(array_map(fn($trait) => $trait->ID, $userTraits));

		// Persona traits parser
		$this->personas = array_values(
			array_map(function ($persona) {

				$traits = array_values(
					array_map(fn($trait) => $trait->ID, $persona->getTraits())
				);

				return [
					'id' => $persona->ID,
					'name' => $persona->NLname,
					'traits' => $traits
				];
			}, $personas)
		);
	}

	public function match(int $limit = 5): array
	{
		$scored = [];

		foreach ($this->personas as $persona) {
			$score = $this->compare($this->userTraits, $persona['traits']);
			$persona['score'] = $score;
			$scored[] = $persona;
		}

		usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
		$topMatches = array_slice($scored, 0, $limit);


		return $topMatches;
	}

	private function compare(array $userTraits, array $personaTraits): int
	{
		$score = 0;
		$max = min(count($userTraits), count($personaTraits));

		for ($i = 0; $i < $max; $i++) {
			if ($userTraits[$i] === $personaTraits[$i]) {
				// trait order match
				$score += ($max * 2) - $i;
			} elseif (in_array($userTraits[$i], $personaTraits)) {
				// trait exists
				$personaTraitRanking = array_search($userTraits[$i], $personaTraits);
				$score += $max - $personaTraitRanking;
			}
		}

		return $score;
	}
}