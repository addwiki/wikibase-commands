<?php

namespace Addwiki\Commands\Wikibase;

use ArrayAccess;
use Asparagus\QueryBuilder;
use DataValues\Deserializers\DataValueDeserializer;
use DataValues\Serializers\DataValueSerializer;
use GuzzleHttp\Client;
use Mediawiki\Api\ApiUser;
use Mediawiki\Api\MediawikiApi;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wikibase\Api\WikibaseFactory;
use Wikibase\DataModel\Entity\PropertyId;

/**
 * @author Addshore
 * @author Lucie-Aimée Kaffee
 */
class WikidataEntityStatementRemover extends Command {

	private $appConfig;

	/**
	 * @var WikibaseFactory
	 */
	private $wikibaseFactory;

	/**
	 * @var MediawikiApi
	 */
	private $wikibaseApi;

	/**
	 * @var SparqlQueryRunner
	 */
	private $sparqlQueryRunner;

	public function __construct( ArrayAccess $appConfig ) {
		$this->appConfig = $appConfig;

		$defaultGuzzleConf = array(
			'headers' => array( 'User-Agent' => 'addwiki - Wikibase Reference As Statement Remover' )
		);
		$guzzleClient = new Client( $defaultGuzzleConf );
		$this->sparqlQueryRunner = new SparqlQueryRunner( $guzzleClient );

		$this->wikibaseApi = new MediawikiApi( "https://www.wikidata.org/w/api.php" );
		$this->wikibaseFactory = new WikibaseFactory(
			$this->wikibaseApi,
			new DataValueDeserializer(
				array(
					'boolean' => 'DataValues\BooleanValue',
					'number' => 'DataValues\NumberValue',
					'string' => 'DataValues\StringValue',
					'unknown' => 'DataValues\UnknownValue',
					'globecoordinate' => 'DataValues\Geo\Values\GlobeCoordinateValue',
					'monolingualtext' => 'DataValues\MonolingualTextValue',
					'multilingualtext' => 'DataValues\MultilingualTextValue',
					'quantity' => 'DataValues\QuantityValue',
					'time' => 'DataValues\TimeValue',
					'wikibase-entityid' => 'Wikibase\DataModel\Entity\EntityIdValue',
				)
			),
			new DataValueSerializer()
		);
		parent::__construct( null );
	}

	protected function configure() {
		$defaultUser = $this->appConfig->offsetGet( 'defaults.user' );

		$this
			->setName( 'wm:wd:reference-as-statement-remove' )
			->setDescription( 'Removes a statement whos property is only for references' )
			->addOption(
				'user',
				null,
				( $defaultUser === null ? InputOption::VALUE_REQUIRED :
					InputOption::VALUE_OPTIONAL ),
				'The configured user to use',
				$defaultUser
			)
			->addOption(
				'property',
				null,
				InputOption::VALUE_REQUIRED,
				'Property to target'
			);
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		// Get options
		$user = $input->getOption( 'user' );
		$userDetails = $this->appConfig->offsetGet( 'users.' . $user );
		if ( $userDetails === null ) {
			throw new RuntimeException( 'User not found in config' );
		}

		$propertyString = $input->getOption( 'property' );
		$property = new PropertyId( $propertyString );
		if ( $propertyString === null || $propertyString === '' || $property === null ) {
			throw new RuntimeException( 'No property given' );
		}

		$output->writeln( 'Running SPARQL query to find items to check' );
		$queryBuilder = new QueryBuilder( array(
			'wdt' => 'http://www.wikidata.org/prop/direct/',
		) );

		$itemIds = $this->sparqlQueryRunner->getItemIdsFromQuery(
			$queryBuilder
			->select( '?item' )
			->where( '?item', 'wdt:' . $propertyString, '?date' )
			->limit( 10000 )
			->__toString()
		);

		// Log in to Wikidata
		$loggedIn =
			$this->wikibaseApi->login( new ApiUser( $userDetails['username'], $userDetails['password'] ) );
		if ( !$loggedIn ) {
			$output->writeln( 'Failed to log in to wikidata wiki' );
			return -1;
		}

		$itemLookup = $this->wikibaseFactory->newItemLookup();

		foreach ( $itemIds as $itemId ) {
			$output->write( $itemId->getSerialization() . ' ' );
			$item = $itemLookup->getItemForId( $itemId );

			foreach ( $item->getStatements()->getIterator() as $statement ) {
				//@todo Remove statemement
				if( $statement->getPropertyId()->equals( $property ) ) {
					$item->getStatements->removeStatementsWithGuid( $statement-getGuid() );
				}
			}
		}

		return 0;
	}
}
