<?php
// sudo pear install --alldeps pear.php.net/Math_Vector-0.7.0
require_once "Math/Vector.php";
require_once "Math/Vector2.php";
require_once "Math/Vector3.php";
require_once "Math/VectorOp.php";

require_once "AntLogger.php";
require_once "Ant.php";

define('MY_ANT', 0);
define('ANTS', 0);
define('DEAD', -1);
define('LAND', -2);
define('FOOD', -3);
define('WATER', -4);
define('UNSEEN', -5);


define('DEBUG_LEVEL', AntLogger::LOG_ALL);

/**
 * Ants
 */
class Ants {

	const Alpha = 'abcdefghijslmnopqrstuvwxyz';

    public $turns = 0;
	public $turn = 1;
    public $rows = 0;
    public $cols = 0;
    public $loadtime = 0;
    public $turntime = 0;
    public $viewradius2 = 0;
    public $attackradius2 = 0;
    public $spawnradius2 = 0;
    public $map;
    public $myAnts = array();
	public $nMyAnts = 0;
    public $enemyAnts = array();
	public $nEnemyAnts = 0;
    public $myHills = array();
    public $enemyHills = array();
    public $deadAnts = array();
    public $food = array();

    public $AIM = array(
        'n' => array(-1, 0),
        'e' => array(0, 1),
        's' => array(1, 0),
        'w' => array(0, -1) );
    public $RIGHT = array (
        'n' => 'e',
        'e' => 's',
        's' => 'w',
        'w' => 'n');
    public $LEFT = array (
        'n' => 'w',
        'e' => 'n',
        's' => 'e',
        'w' => 's');
    public $BEHIND = array (
        'n' => 's',
        's' => 'n',
        'e' => 'w',
        'w' => 'e'
        );

	/**
	 * Logger
	 * 
	 * @var AntLogger
	 */
	public $logger = null;
	
	/**
	 * 
	 * @param array $args
	 */
	function __construct($args = array()) {
		$this->logger = new AntLogger(array(
			'logLevel' =>  DEBUG_LEVEL  // - AntLogger::LOG_INPUT - AntLogger::LOG_OUTPUT - AntLogger::LOG_MAPDUMP
		));
	}
	
    public function issueOrder($aRow, $aCol, $direction) {
		$this->logger->write(sprintf("Raw output: o %s %s %s", $aRow, $aCol, $direction), AntLogger::LOG_OUTPUT);
        printf("o %s %s %s\n", $aRow, $aCol, $direction);
        flush();
    }

    public function finishTurn() {
		$this->logger->write("Finished turn " . $this->turn, AntLogger::LOG_GAME_FLOW);
        echo("go\n");
		$this->turn++;
        flush();
    }
    
    public function setup($data) {
		
		$this->logger->write("Starting setup processing start for turn " . $this->turn, AntLogger::LOG_GAME_FLOW);
		
        foreach ($data as $line) {
            if (strlen($line) > 0) {
                $tokens = explode(' ',$line);
                $key = $tokens[0];
                if (property_exists($this, $key)) {
                    $this->{$key} = (int)$tokens[1];
                }
            }
        }
        for ($row = 0; $row < $this->rows; $row++) {
            for ($col = 0; $col < $this->cols; $col++) {
                $this->map[$row][$col] = LAND;
            }
        }

		$this->dumpGame(AntLogger::LOG_GAME_FLOW);
    }

    /** not tested */

    /**
     * update
     * 
     */
    public function update($data) {
		
		$this->logger->write("<GREEN>Starting update processing for turn " . $this->turn . '.</GREEN>', AntLogger::LOG_GAME_FLOW);

		$this->logger->write("Raw Input - turn " . $this->turn, AntLogger::LOG_INPUT);
		$this->logger->write("----------------", AntLogger::LOG_INPUT);
		$this->logger->write(implode("\n", $data), AntLogger::LOG_INPUT);

		$this->dumpTurn(AntLogger::LOG_GAME_FLOW);

        // clear ant and food data
        foreach ($this->myAnts as $ant) {
            list($row, $col) = $ant->ppos;
            $this->map[$row][$col] = LAND;
        }

        foreach ($this->enemyAnts as $ant) {
            list($row, $col) = $ant;
            $this->map[$row][$col] = LAND;
        }
        $this->enemyAnts = array();

        foreach ($this->deadAnts as $ant) {
            list($row, $col) = $ant->pos;
            $this->map[$row][$col] = LAND;
        }
        $this->deadAnts = array();

        foreach ($this->food as $ant) {
            list($row, $col) = $ant->pos;
            $this->map[$row][$col] = LAND;
        }

        $this->food = array();
        $this->myHills = array();
        $this->enemyHills = array();

		// turn input processing
		//
        // update map and create new ant and food lists
		//
		// store long term?
        foreach ($data as $line) {
            if (strlen($line) > 0) {
                $tokens = explode(' ',$line);

                if (count($tokens) >= 3) {
                    $row = (int)$tokens[1];
                    $col = (int)$tokens[2];
                    if ($tokens[0] == 'a') {				// a = live ant, format: w row col owner
                        $owner = (int)$tokens[3];
                        $this->map[$row][$col] = mb_substr(self::Alpha, $owner, 1);
                        if($owner === 0) {
							if ($this->turn === 1) {
								$ant = new Ant(array(
									'row' => $row,
									'col' => $col, 
									'owner' => (int)$owner,
									'debug' => DEBUG_LEVEL,
									'mission' => new MissionGoNESW(array(
										'debug' => DEBUG_LEVEL
									))
								));
								$this->addAnt($ant);
							} else {
								$ant = $this->lookupAnt($row, $col);
								if (!$ant) {
									$this->logger->write("Lost ant at $row, $col", AntLogger::LOG_ERROR);
								}
							}
                        } else {
							$this->enemyAnts[] = array($row, $col);
                        }
                    } elseif ($tokens[0] == 'f') {			// f = food, format: f row col
                        $this->map[$row][$col] = FOOD;
                        $this->food []= array($row, $col);
                    } elseif ($tokens[0] == 'w') {			// w = water, format: w row col
                        $this->map[$row][$col] = WATER;
                    } elseif ($tokens[0] == 'd') {			// dead ant, format: d row col owner

						$this->deadAnts[] = array($row,$col);
						
                        if ($this->map[$row][$col] === LAND) {
                            $this->map[$row][$col] = DEAD;
                        }

						if (DEBUG_LEVEL) {
							$ant = $this->lookupAnt($row, $col);
							if ($ant) {
								$this->logger->write(sprintf("CASUALTY: %s", $ant), AntLogger::LOG_GAME_FLOW);
							} else {
								$this->logger->write(sprintf("KILLED: (%d, %d)", $row, $col), AntLogger::LOG_GAME_FLOW);
							}
						}

						$this->deadAnts[] = array($row,$col);

                    } elseif ($tokens[0] == 'h') {			// h = hill, format: w row col owner
                        $owner = (int)$tokens[3];
                        if ($owner === 0) {
                            $this->myHills []= array($row, $col, $owner);
                        } else {
                            $this->enemyHills []= array($row, $col, $owner);
                        }
                    }
                } // tokens >- 3
            } // not empty line
        } // each line

		$this->dumpMap(AntLogger::LOG_MAPDUMP);

		$this->logger->write("Update processing for turn " . $this->turn . " complete", AntLogger::LOG_GAME_FLOW);
    }

    public function passable($row, $col) {
        return $this->map[$row][$col] > WATER;
    }

    public function unoccupied($row, $col) {
        return in_array($this->map[$row][$col], array(LAND, DEAD));
    }

    /**
     *
     */
    public function destination($row, $col, $direction) {
        list($dRow, $dCol) = $this->AIM[$direction];
        $nRow = ($row + $dRow) % $this->rows;
        $nCol = ($col + $dCol) % $this->cols;
        if ($nRow < 0) { 
			$nRow += $this->rows;
		}
        if ($nCol < 0) {
			$nCol += $this->cols;
		}
        return array($nRow, $nCol);
    }

	/**
	 * Distance between two cells taking into account board wrapping
	 *
	 * @param integer $row1
	 * @param integer $col1
	 * @param integer $row2
	 * @param integer $col2
	 * @return integer
	 */
    public function distance($row1, $col1, $row2, $col2) {
        $dRow = abs($row1 - $row2);
        $dCol = abs($col1 - $col2);

        $dRow = min($dRow, $this->rows - $dRow);
        $dCol = min($dCol, $this->cols - $dCol);

        return sqrt($dRow * $dRow + $dCol * $dCol);
    }

    /**
	 * Calc the direction(n, r, s, w) based on current square and next square.
     * 
	 * @param intger $row1
	 * @param intger $col1
	 * @param intger $row2
	 * @param intger $col2
	 * @return string
	 */
    public function direction($row1, $col1, $row2, $col2) {
        $d = array();
        $row1 = $row1 % $this->rows;
        $row2 = $row2 % $this->rows;
        $col1 = $col1 % $this->cols;
        $col2 = $col2 % $this->cols;

        if ($row1 < $row2) {
            if ($row2 - $row1 >= $this->rows/2) {
                $d[]= 'n';
            }
            if ($row2 - $row1 <= $this->rows/2) {
                $d[]= 's';
            }
        } elseif ($row2 < $row1) {
            if ($row1 - $row2 >= $this->rows/2) {
                $d[]= 's';
            }
            if ($row1 - $row2 <= $this->rows/2) {
                $d[]= 'n';
            }
        }
        if ($col1 < $col2) {
            if ($col2 - $col1 >= $this->cols/2) {
                $d[]= 'w';
            }
            if ($col2 - $col1 <= $this->cols/2) {
                $d[]= 'e';
            }
        } elseif ($col2 < $col1) {
            if ($col1 - $col2 >= $this->cols/2) {
                $d[]= 'e';
            }
            if ($col1 - $col2 <= $this->cols/2) {
                $d[]= 'w';
            }
        }
        return $d;

    }

	/**
	 * Add an Ant
	 * 
	 * @param object $ant
	 * @return boolean Return an Ant object on success, false otherwise;
	 */
	public function addAnt($ant) {
		if ($ant->owner !== 0) {
			$this->logger->write('Ants.addAnt() - Not my ant', AntLogger::LOG_ERROR);
		}
		$this->myAnts[] = $ant;
		$this->nMyAnts++;
	}
	
	/**
	 * Lookup one of my ants based on it's position.
	 * 
	 * @param integer|array $arg1
	 * @param integer $arg2
	 * @return object|false Return an Ant object on success, false otherwise;
	 */
	public function lookupAnt($arg1, $arg2 = null) {
		if (is_array($arg1)) {
			$row = (isset($arg1['row']) ? $arg1['row'] : $arg1[0]);
			$col = (isset($arg1['col']) ? $arg1['col'] : $arg1[0]);
		} else {
			$row = $arg1;
			$col = $arg2;
		}
		
		for ($i = 0; $i < $this->nMyAnts; $i++) {
			$ant = $this->myAnts[$i];
			if ($ant->row === $row && $ant->col == $col) {
				return $ant;
			}
		}

		return false;
	}

	/**
	 * Is map[r,c] a hive? If so, return the owner.
	 *
	 * @param integer $row
	 * @param integer $col
	 * @return integer|false Return owner if found, false otherwise.
	 */
	public function isHive($row, $col) {
		foreach ($this->myHills as $h) {
			if ($h[0] === $row && $h[1] === $col) {
				return $h[2];
			}
		}

		foreach ($this->enemyHills as $h) {
			if ($h[0] === $row && $h[1] === $col) {
				return $h[2];
			}
		}

		return false;
	}

	/**
	 * Start the stdin loop
	 *
	 *	.   = land
	 *	%   = water
	 *	*   = food
	 *	!   = dead ant or ants
	 *	?   = unseen territory
	 *	a-j = ant
	 *	A-J = ant on its own hill
	 *	0-9 = hill
	 *
	 * @param Ant $bot
	 */
    public function dumpMap($grp = AntLogger::LOG_ALL) {
		$this->logger->write('', $grp, array('noEndline' => false));
		for ($i = 0, $ilen = count($this->map); $i < $ilen; $i++) {
			$this->logger->write('', $grp, array('noEndline' => true));
			for ($j = 0, $jlen = count($this->map[$i]); $j < $jlen; $j++) {
				switch ($this->map[$i][$j]) {
					case DEAD:
						$char = '!';
						break;
					case LAND:
						$owner = $this->isHive($i, $j);
						if ($owner === false) {
							$char = '.';
						} else {
							$char = $owner;
						}
						break;
					case FOOD:
						$char = '*';
						break;
					case WATER:
						$char = '%';
						break;
					case UNSEEN:
						$char = '?';
						break;
					default:
						$hiveOwner = $this->isHive($i, $j);
						if ($hiveOwner === false) {
							$char = $this->map[$i][$j];
						} else {
							$char = strtoupper($this->map[$i][$j]);
						}
				}
				$this->logger->write($char, $grp, array('noEndline' => true));
			}
			$this->logger->write('', $grp);
		}
		$this->logger->write('', $grp, array('noEndline' => false));
		
		$mh = '';
		foreach ($this->myHills as $h) {
			array_splice($h, 2, 1);
			$mh .= '(' .implode(',', $h) . '), ';
		}

		$eh = '';
		foreach ($this->enemyHills as $h) {
			$eh .= '(' .implode(',', $h) . '), ';
		}
		
		$this->logger->write('MyHives: ' . substr($mh, 0, -2) . '. Enemy Hives: ' . substr($eh, 0, -2) . ".\n");
	}

	/**
	 *
	 */
    public function dumpAnts($grp = AntLogger::LOG_ALL) {
		$this->logger->write('Dead ants (' .  count($this->deadAnts) . '):', $grp);
		$this->logger->write('Enemy ants (' .  count($this->enemyAnts) . '):', $grp);
		$this->logger->write('My ants (' . $this->nMyAnts . '):', $grp);
		for ($i = 0, $len = count($this->myAnts); $i < $len; $i++) {
			$this->logger->write(sprintf("  %s", $this->myAnts[$i]), $grp);
		}
	}

	/**
	 *
	 */
    public function dumpGame($grp = AntLogger::LOG_ALL) {
		$this->logger->write('Game Summary', $grp);
		$this->logger->write('----------------', $grp);
		$this->logger->write('Map:' . $this->rows . 'x' . $this->cols, $grp);
		$this->logger->write('Turns:' . $this->turns, $grp);
		$this->dumpAnts($grp);
	}

	/**
	 * Start the strdin loop
	 *
	 * @param Ant $bot
	 */
    public function dumpTurn($grp = AntLogger::LOG_ALL) {
		$this->logger->write("\nTurn " . $this->turn . " Initial State Summary", $grp);
		$this->logger->write('----------------', $grp);
		$this->dumpAnts($grp);
		$this->logger->write("\n");
	}

	/**
	 * Main game loop
	 *
	 * @param Ant $bot
	 */
    public static function run($bot){
		$ants = new Ants();
		$map_data = array();
		while (true) {
			$current_line = fgets(STDIN,1024);
			$current_line = trim($current_line);
			if ($current_line === 'ready') {
				$ants->setup($map_data);
				$ants->finishTurn();
				$map_data = array();
			} elseif ($current_line === 'go') {
				$ants->update($map_data);
				$bot->doTurn($ants); // ants == game data
				$ants->finishTurn();
				$map_data = array();
			} else {
				$map_data[] = $current_line;
			}
		}
	}
}
