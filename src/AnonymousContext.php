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
    $this->iWaitMiliseconds(500);
  }

  /**
   * @Then I wait :time miliseconds
   */
  public function iWaitMiliseconds($time) {
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
    foreach ($entityTable->getHash() as $entityHash) {
      $entityHash = $this->prepareEntity($entity_type, $entityHash);

      $entity = $entityStorage->create($entityHash);
      $entity->save();
      $this->entities[$entity_type][] = $entity;
    }
  }

  /**
   * @param string $entityType
   * @param array $entity
   *
   * @return array
   * @throws \Exception
   */
  private function prepareEntity($entityType, array $entity) {

    $fieldDefinitions = \Drupal::entityManager()
      ->getFieldStorageDefinitions($entityType);
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
            $entity[$field] = [end($files)];
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
            $entity[$field] = [end($entities)];
          }
        }
      }
    }

    return $this->parseMultiColumnFields($entity);
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

  /**
   * @When /^I scroll down (\d+) pixels$/
   */
  public function iScrollDownPixels($pixels) {
    $this->getSession()->getDriver()->executeScript("scroll(0, {$pixels});");
  }

  /**
   * @When /^I scroll up (\d+) pixels$/
   */
  public function iScrollUpPixels($pixels) {
    $this->getSession()->getDriver()->executeScript("scroll(0, -{$pixels});");
  }

  /**
   * @When /^I scroll to the top of the page$/
   */
  public function iScrollTop() {
    $this->getSession()->getDriver()->executeScript("scroll(0, 0);");
  }

  /**
   * @When /^I scroll to the bottom of the page$/
   */
  public function iScrollBottom() {
    $this->getSession()
      ->getDriver()
      ->executeScript("window.scrollTo(0,document.body.scrollHeight);");
  }

  /**
   * @When I scroll the :selector element into view
   */
  public function iScrollIntoView($selector) {
    $type = substr($selector, 0, 1);
    $selector = substr($selector, 1);

    switch ($type) {
      case "#":
        if (empty($this->getSession()->getPage()->findById($selector))) {
          throw new \Exception("No element with id '{$selector}' found.'");
        }
        $this->getSession()
          ->getDriver()
          ->executeScript("document.getElementById('{$selector}').scrollIntoView(true);");
        break;

      case ".":
        if (empty($this->getSession()->getPage()->find('css', ".$selector"))) {
          throw new \Exception("No element with class '{$selector}' found.'");
        }
        $this->getSession()
          ->getDriver()
          ->executeScript("document.getElementsByClassName('{$selector}')[0].scrollIntoView(true);");

        break;
      default:
        throw new \Exception("You should provide a class or id");
    }
  }

  /**
   * @When I am viewing a :bundle :entity_type with :paragraph_type paragraph in :field_name:
   */
  public function viewingEntityWithParagraph($bundle, $entity_type, $paragraph_type, $field_name, TableNode $table) {
    $entityTypeManager = \Drupal::entityTypeManager();
    $entityStorage = $entityTypeManager->getStorage($entity_type);

    $labelKey = $entityTypeManager->getDefinition($entity_type)
      ->getKey('label');
    $bundleKey = $entityTypeManager->getDefinition($entity_type)
      ->getKey('bundle');

    $paragraph = $this->createParagraph($paragraph_type, $table);

    $entity = $entityStorage->create([
      $labelKey => bin2hex(random_bytes(10)),
      $bundleKey => $bundle,
      $field_name => [$paragraph],
    ]);
    try {
      $entity->save();
      $this->entities[$entity_type][] = $entity;
    } catch (\Exception $e) {
      echo "Entity could not be saved: \n";
      echo print_r($entity, TRUE);
    }

    $this->getSession()->visit($this->locatePath($entity->toUrl()->toString()));
  }

  /**
   * @param $paragraph_type
   * @param \Behat\Gherkin\Node\TableNode $table
   *
   * @return \Drupal\Core\Entity\EntityInterface
   */
  private function createParagraph($paragraph_type, TableNode $table) {
    $paragraphStorage = \Drupal::entityTypeManager()->getStorage('paragraph');
    $values = ['type' => $paragraph_type] + $this->parseMultiColumnFields($table->getRowsHash());
    $preparedValues = $this->prepareEntity('paragraph', $values);
    $paragraph = $paragraphStorage->create($preparedValues);
    try {
      $paragraph->save();
    } catch (\Exception $e) {
      echo "Paragraph could not be saved: \n";
      echo print_r($paragraph, TRUE);
    }
    return $paragraph;
  }

  /**
   * @param array $fields
   *   Array of fields to parse.
   *
   * @return array
   *   The parsed array.
   */
  protected function parseMultiColumnFields(array $fields) {
    foreach ($fields as $name => $value) {
      $colonPosition = strpos($name, ':');
      if ($colonPosition !== FALSE) {
        $fieldName = substr($name, 0, $colonPosition);
        $columnName = substr($name, $colonPosition + 1);
        $fields[$fieldName][$columnName] = $value;
        unset($fields[$name]);
      }
    }

    foreach ($fields as $name => $value) {
      if (is_array($value)) {
        $fields[$name] = $this->parseMultiColumnFields($fields[$name]);
      }
    }

    return $fields;
  }

  /**
   * @Given a :paragraph_type paragraph on :field_name of :entity_type :index:
   */
  public function paragraphOnEntityField($paragraph_type, $field_name, $entity_type, $index, \Behat\Gherkin\Node\TableNode $paragraph_table) {
    $entityTypeManager = \Drupal::entityTypeManager();
    if (!$entityTypeManager->getStorage('paragraphs_type')
      ->load($paragraph_type)) {
      throw new \Exception("Unknown paragraphs type: $paragraph_type");
    }

    if (!isset($this->entities[$entity_type][$index])) {
      throw new \Exception("No $entity_type created for index $index");
    }

    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $this->entities[$entity_type][$index];
    if (!$entity->hasField($field_name)) {
      throw new \Exception("The $entity_type at index $index does not have a $field_name field");
    }

    $paragraph = $this->createParagraph($paragraph_type, $paragraph_table);
    $entity->{$field_name}[] = $paragraph;

    $entity->save();
    $this->entities['paragraph'][] = $paragraph;
  }

  /**
   * @Given I am viewing :entity_type :index
   */
  public function viewEntityWithIndex($entity_type, $index) {
    $entityTypeManager = \Drupal::entityTypeManager();

    if (!isset($this->entities[$entity_type][$index])) {
      throw new \Exception("No $entity_type created for index $index");
    }

    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $this->entities[$entity_type][$index];
    $this->visitPath($entity->toUrl()->toString());
  }

  /**
   * @Then I should not see the button :button in the :region( region)
   * @Then I should not see the :button button in the :region( region)
   */
  public function assertRegionButton($button, $region) {
    $regionObj = $this->getRegion($region);

    $buttonObj = $regionObj->findButton($button);
    if (!empty($buttonObj)) {
      throw new \Exception(sprintf("The button '%s' was found in the region %s on the page %s", $button, $region, $this->getSession()
        ->getCurrentUrl()));
    }
  }

}
