<?php

namespace TeamDeeson\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Behat\Context\SnippetAcceptingContext;
use Behat\Gherkin\Node\TableNode;
use Drupal\DrupalExtension\Context\MinkContext;

/**
 * Defines application features from the specific context.
 */
class AnonymousContext extends MinkContext implements Context, SnippetAcceptingContext {

  /** @var array */
  private $entities = [];

  /**
   * @Then I should see an element with the :selector selector in the :regionName region
   *
   * @param string $selector
   *   The css selector.
   * @param string $regionName
   *   The region name.
   *
   * @throws \Exception
   */
  public function assertSelectorInRegion($selector, $regionName) {
    $regionObj = $this->getRegion($regionName);

    $result = $regionObj->find('css', $selector);
    if (empty($result)) {
      $currentUrl = $this->getSession()->getCurrentUrl();
      throw new \Exception(sprintf('No class "%s" in the "%s" region on the page %s', $selector, $regionName, $currentUrl));
    }
  }

  /**
   * Finds and navigates to content of a given type with a given title.
   *
   * @When I am viewing a/an existing :type :entity_type with the title :title
   */
  public function viewNode($type, $title, $entity_type) {
    $entityStorage = \Drupal::entityTypeManager()->getStorage($entity_type);
    $query = $entityStorage->getQuery();

    $entityType = $entityStorage->getEntityType();
    $results = $query->condition($entityType->getKey('bundle'), $type)
      ->condition($entityType->getKey('label'), $title)
      ->execute();

    if (empty($results)) {
      throw new \Exception("No {$type} with the title '{$title}' could be found");
    }

    $entity = $entityStorage->load(end($results));
    $url = $entity->toUrl();
    $path = $url->getInternalPath();
    $this->assertAtPath($url->toString());
  }

  /**
   * Checks, that page contains specified text within a parent element with a
   * given css selector.
   * Example: Then I should see "Who is the Batman?" rendered as "block"
   * Example: And I should see "Who is the Batman?" rendered as "block"
   *
   * @Then /^(?:|I )should see "(?P<text>(?:[^"]|\\")*)" rendered as (?P<selector>(?:[^"]|\\")*)$/
   */
  public function assertAtLeastOneElementContainsText($text, $selector) {
    /** @var NodeElement[] $items */
    $items = $this->getSession()->getPage()->findAll('css', $selector);

    /** @var \Behat\Mink\Element\NodeElement $item */
    while ($item = array_shift($items)) {
      $regex = '/' . preg_quote($text, '/') . '/ui';
      if (preg_match($regex, $item->getText())) {
        return;
      }
    }

    throw new \Exception("No {$selector} element containing '{$text}' could be found.'");
  }

  /**
   * @Then I wait for the page to load
   */
  public function iWaitForPageToLoad() {
    $this->iWaitMilisecondsForPageToLoad(500);
  }

  /**
   * @Then I wait :time miliseconds for the page to load
   */
  public function iWaitMilisecondsForPageToLoad($time) {
    $this->getSession()->wait($time);
  }

  /**
   * @When I land on the :error error page
   */
  public function iLandOnThePage($error) {
    $path = '/';
    switch ($error) {
      case 404:
        $path = $this->locatePath('/this-page-does-not-exist-for-sure-no-really-it-does-not');
        break;

      case 403:
        // No production user should ever have access to the views configuration
        // page. If you got here because you're testing as a user with
        // super-user privileges, then turn around and rethink your approach,
        // because no production user should EVER have superuser privileges.
        $path = $this->locatePath('/admin/structure/views');
        break;

      default:
        throw new \Exception("Invalid error code. Only 404 and 403 are supported");
    }

    $this->getSession()->visit($path);
    $this->assertResponseStatus($error);
  }

  /**
   * @Given :menu_name menu items:
   * | title     | url        |
   * | Home      | /          |
   * | Some page | /some-path |
   */
  public function menuItems($menu_name, TableNode $items) {
    foreach ($items as $itemInfo) {
      $menu_link = \Drupal::entityTypeManager()
        ->getStorage('menu_link_content')
        ->create([
          'title' => $itemInfo['title'],
          'link' => ['uri' => 'internal:' . $itemInfo['url']],
          'menu_name' => $menu_name,
          'expanded' => TRUE,
        ]);
      $menu_link->save();

      $this->entities['menu_link_content'][] = $menu_link;
    }
  }

  /**
   * Remove any created entities.
   *
   * @AfterScenario
   */
  public function cleanEntities() {
    foreach ($this->entities as $entity_type => $entities) {
      try {
        \Drupal::entityTypeManager()
          ->getStorage($entity_type)
          ->delete($entities);
      } catch (\Exception $e) {
        // Don't stop deleting entities when deletion fails for one entity type.
      }
    }
  }

  /**
   * @Given :entity_type entities:
   */
  public function createEntities($entity_type, TableNode $entityTable) {
    if (empty($this->entities[$entity_type])) {
      $this->entities[$entity_type] = [];
    }

    $entityStorage = \Drupal::entityTypeManager()->getStorage($entity_type);
    $fieldDefinitions = \Drupal::entityManager()
      ->getFieldStorageDefinitions($entity_type);
    foreach ($entityTable->getHash() as $entityHash) {
      $entityHash = (array) $this->prepareEntity($entity_type, (object) $entityHash, $fieldDefinitions);

      $entity = $entityStorage->create($entityHash);
      $entity->save();
      $this->entities[$entity_type][] = $entity;
    }
  }

  /**
   * @param \stdClass $entity
   * @param \Drupal\Core\Field\FieldDefinitionInterface[] $fieldDefinitions
   *
   * @return array
   * @throws \Exception
   */
  private function prepareEntity($entityType, \stdClass $entity, array $fieldDefinitions) {
    $entityTypeManager = \Drupal::entityTypeManager();
    $entityKeys = $entityTypeManager->getStorage($entityType)
      ->getEntityType()
      ->getKeys();

    foreach ($entity as $field => $value) {
      if (!in_array($field, $entityKeys) && key_exists($field, $fieldDefinitions)) {
        $definition = $fieldDefinitions[$field];

        // Load any referenced files.
        if ($definition->getType() === 'image') {
          $fileStorage = $entityTypeManager->getStorage('file');
          $files = $fileStorage->loadByProperties(['uri' => $value]);
          if (empty($files)) {
            throw new \Exception("No file with uri {$value} exists.");
          }
          else {
            $entity->{$field} = end($files);
          }
        }

        // Load any referenced entities.
        if ($definition->getType() === 'entity_reference') {
          $targetType = $definition->getSetting('target_type');
          $entityStorage = \Drupal::entityTypeManager()
            ->getStorage($targetType);
          $labelKey = $entityStorage->getEntityType()->getKey('label');

          $entities = $entityStorage->loadByProperties([$labelKey => $value]);
          if (empty($entities)) {
            throw new \Exception("No {$targetType} with {$labelKey} {$value} exists");
          }
          else {
            $entity->{$field} = end($entities);
          }
        }
      }
    }

    return $entity;
  }

  /**
   * @Then I should see the :pattern pattern
   */
  public function assertPattern($pattern) {
    if (preg_match($pattern, $this->getSession()->getPage()->getText())) {
      return;
    }

    if (preg_match($pattern, $this->getSession()->getPage()->getHtml())) {
      return;
    }

    throw new \Exception("Pattern {$pattern} was not matched anywhere on the page.");
  }

}
