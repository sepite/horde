<?php
/**
 * Test the basic list query.
 *
 * PHP version 5
 *
 * @category   Kolab
 * @package    Kolab_Storage
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.fsf.org/copyleft/lgpl.html LGPL
 * @link       http://pear.horde.org/index.php?package=Kolab_Storage
 */

/**
 * Prepare the test setup.
 */
require_once dirname(__FILE__) . '/../../../Autoload.php';

/**
 * Test the basic list query.
 *
 * Copyright 2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @category   Kolab
 * @package    Kolab_Storage
 * @subpackage UnitTests
 * @author     Gunnar Wrobel <wrobel@pardus.de>
 * @license    http://www.fsf.org/copyleft/lgpl.html LGPL
 * @link       http://pear.horde.org/index.php?package=Kolab_Storage
 */
class Horde_Kolab_Storage_Unit_List_Query_BaseTest
extends Horde_Kolab_Storage_TestCase
{
    public function testByTypeReturnsArray()
    {
        $factory = new Horde_Kolab_Storage_Factory();
        $query = $factory->createListQuery('Base', $this->getNullList($factory));
        $this->assertType('array', $query->listByType('test'));
    }

    public function testListCalendarsListsCalendars()
    {
        $factory = new Horde_Kolab_Storage_Factory();
        $query = $factory->createListQuery('Base', $this->getAnnotatedList($factory));
        $this->assertEquals(array('INBOX/Calendar'), $query->listByType('event'));
    }

    public function testListTasklistsListsTasklists()
    {
        $factory = new Horde_Kolab_Storage_Factory();
        $query = $factory->createListQuery('Base', $this->getAnnotatedList($factory));
        $this->assertEquals(array('INBOX/Tasks'), $query->listByType('task'));
    }

    public function testTypeReturnsArray()
    {
        $factory = new Horde_Kolab_Storage_Factory();
        $query = $factory->createListQuery('Base', $this->getNullList($factory));
        $this->assertType('array', $query->listTypes());
    }

    public function testTypeReturnsAnnotations()
    {
        $factory = new Horde_Kolab_Storage_Factory();
        $query = $factory->createListQuery('Base', $this->getAnnotatedList($factory));
        $this->assertEquals(
            array(
                'INBOX/Calendar' => 'event',
                'INBOX/Contacts' => 'contact',
                'INBOX/Notes' => 'note',
                'INBOX/Tasks' => 'task',
            ),
            $query->listTypes()
        );
    }

    public function testAnnotationsReturnsHandlers()
    {
        $factory = new Horde_Kolab_Storage_Factory();
        $query = $factory->createListQuery('Base', $this->getAnnotatedList($factory));
        foreach ($query->listFolderTypeAnnotations() as $folder => $type) {
            $this->assertInstanceOf('Horde_Kolab_Storage_Folder_Type', $type);
        };
    }
}
