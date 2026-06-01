<?php
require_once 'persona_matcher.php';

$site = $this->_site;
$client = new modules_clientlogin_model_client($site->_session['client']->ID);


function getPersonalityTraits($site, $client)
{
	$statements = $client->getStatementsForClient($client->ID);

	$statementsCleaned = array_values(array_map(function ($statement) {
		$chosen = $statement->answer == 'a' ? $statement->opinion_a : $statement->opinion_b;
		$notChosen = $statement->answer != 'a' ? $statement->opinion_a : $statement->opinion_b;

		return [
			'chosen' => $chosen,
			'notChosen' => $notChosen
		];
	}, $statements));

	$config = parse_ini_file(CREEM_PATH . 'config.ini');
	$openaiDevKey = $config['dev.openai.api.key'];

	$OpenAIClient = OpenAI::client($openaiDevKey);

	function getGPTReponse($client, $model = 'gpt-4o', $input, $schema)
	{
		try {
			$result = $client->chat()->create([
				'model' => $model,
				'messages' => $input,
				'response_format' => [
					'type' => 'json_schema',
					'json_schema' => $schema
				],
			]);

			return $result;
		} catch (\Throwable $th) {
			throw $th;
		}
	}


	$traitsSchema = [
		'name' => 'personality_traits',
		'strict' => true,
		'schema' => [
			'type' => 'object',
			'properties' => [
				'traits' => [
					'type' => 'array',
					'description' => 'An array of personality traits, each defined by an id and a score.',
					'items' => [
						'type' => 'object',
						'properties' => [
							'id' => [
								'type' => 'number',
								'description' => 'The unique identifier for the personality trait.',
							],
							'score' => [
								'type' => 'number',
								'description' => 'The score assigned to the personality trait.',
							],
						],
						'required' => ['id', 'score'],
						'additionalProperties' => false,
					],
				],
			],
			'required' => ['traits'],
			'additionalProperties' => false,
		],
	];

	$personaModel = new modules_Persona_model_persona();
	$traits = $personaModel->getTraits(filter: 'status = 1');
	$personalityTraitsSet = array_map(fn($item) => ['id' => $item->ID, 'name' => $item->NLname], $traits);

	$traitListText = "";
	foreach ($personalityTraitsSet as $trait) {
		$traitListText .= "{$trait['id']}. {$trait['name']}\n";
	}


	$traitsInput = [
		[
			'role' => 'system',
			'content' => "Interpret the chosen and not chosen answers to generate an array of personality traits, represented by their IDs from the provided list, each with an associated score between 0 and 100. Ensure that no trait ID is duplicated and do not create new personality traits.\n
			 \n
			 - **Input**: A set of chosen and not chosen answers related to personality assessment.\n
			 - **Output**: An array of distinct personality IDs from the provided list, each accompanied by a score reflecting the influence of the chosen or not chosen answer. Ensure no trait ID is duplicated.\n
			 \n
			 # Available Personality Traits:\n
			 \n
			 $traitListText
			 \n
			 # Steps\n
			 \n
			 1. **Analyze Inputs**: Examine both chosen and not chosen answers to identify relevant personality traits, ensuring each chosen answer has at least one associated trait ID and the non-chosen answer can have them.\n
			 2. **Determine Trait Influence**: For each trait ID, assess the influence of chosen versus not chosen answers on the score.\n
			 3. **Assign Scores**: Convert the influence of chosen/not chosen answers into a numerical score ranging from 0 to 100. Avoid scores that are strictly multiples of 10 or halves of 10 for a more natural feel.\n
			 4. **Ensure Uniqueness**: Compile the traits into an array, ensuring each trait ID appears only once.\n
			 \n
			 # Output Format\n
	 
			 The output should be a JSON array where each element is an object with two properties:\n
			 - id: The ID of the personality trait.\n
			 - score: A numerical representation of the trait's score.\n
			 \n
			 json\n
			 [
				 {
					 \"id\": 13,
					 \"score\": 85
				 },
				 {
					 \"id\": 12,
					 \"score\": 47
				 }
			 ]
			 \n\n
	 
			 # Examples\n\n
	 
			 **Example 1**\n
			 - **Input**: Chosen answers: \"I love connecting with people\" Not chosen answers: \"I work best alone\", \"I avoid gatherings\".\n
			 - **Output**:\n
			 json\n
			 [
				 {
				 \"id\": 7,
				 \"score\": 89
				 },
				 {
				 \"id\": 3,
				 \"score\": 72
				 }
			 ]
			 \n\n
	 
			 **Example 2**\n
			 - **Input**: Chosen answers: \"I value my privacy\", \"I often reflect internally\". Not chosen answers: \"I'm always talking\", \"I often share my thoughts\".\n
			 - **Output**:\n
			 json\n
			 [
				 {
				 \"id\": 1,
				 \"score\": 94
				 },
				 {
				 \"id\": 4,
				 \"score\": 75
				 }
			 ]
			 \n\n
	 
			 # Notes\n\n
	 
			 - Ensure that each trait ID only appears once with the highest valid score calculated based on the input.\n
			 - Be mindful of balancing the influence of chosen and not chosen answers on the final scores.\n
			 - Avoid score values that are easy multiples of 10 or halves of 10 to meet the requirement for natural and less rounded scores."
		],
		[
			'role' => 'user',
			'content' => json_encode($statementsCleaned)
		]
	];


	$traitsResponse = getGPTReponse($OpenAIClient, 'gpt-4o', $traitsInput, $traitsSchema);
	$traitsJSON = $traitsResponse->choices[0]->message->content;

	$clientID = $site->_session['client']->ID;
	$client->removeAllTraitsForClient($clientID);

	$traitsArr = json_decode($traitsJSON)->traits;
	foreach ($traitsArr as $trait) {
		$clientTrait = new modules_clientlogin_model_trait();
		$clientTrait->clientID = $clientID;
		$clientTrait->traitID = $trait->id;
		$clientTrait->score = $trait->score;
		$clientTrait->save();
	}

	$client->statements_at_last_request = count($statementsCleaned);
	$client->save();
}

function matchPersona($client, $personaModel)
{
	$clientTraits = $client->getTraitsForClient($client->ID);
	$personas = $personaModel->getItemsForCategory(1);

	$matcher = new PersonaMatcher($clientTraits, $personas);
	$matchedPersonas = $matcher->match();

	$client->personaID = $matchedPersonas[0]['id'];
	$client->save();
}

function initTraits($site, $client)
{
	if (!is_object($site->_session['client'])) return;

	$personaModel = new modules_Persona_model_persona();

	$statementsCount = $client->getStatementsCountForClient($client->ID);
	$traitsCount = $client->getTraitsCountForClient($client->ID);
	$personaUnlockAmount = $personaModel->getPersonaThreshold();

	$personaUnlocked = $statementsCount >= $personaUnlockAmount;
	$personaUnlockedWithoutTraits = $personaUnlocked && $traitsCount == 0;


	if (
		$personaUnlockedWithoutTraits
		|| $personaUnlocked && $client->hasChangedStatementsCount()
		|| $personaUnlocked && !$client->personaID
	) {
		getPersonalityTraits($site, $client);
		matchPersona($client, $personaModel);
	}
}

initTraits($site, $client);
