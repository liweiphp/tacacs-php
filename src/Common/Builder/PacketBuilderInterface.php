<?php
/**
 * Tacacs_Client is a sample TACACS+ client for authentication purposes.
 *
 * This source code is provided as a demostration for TACACS+ authentication.
 *
 * PHP version 5
 *
 * @category Common
 * @package  TacacsPlus
 * @author   Martín Claro <martin.claro@gmail.com>
 * @license  http://www.gnu.org/copyleft/gpl.html GNU General Public License
 * @link     https://gitlab.com/martinclaro
 */
namespace TACACS\Common\Builder;

/**
 * PacketHeader represents a TACACS+ Packet Header.
 *
 * @category Common
 * @package  TacacsPlus
 * @author   Martín Claro <martin.claro@gmail.com>
 * @license  http://www.gnu.org/copyleft/gpl.html GNU General Public License
 * @link     https://gitlab.com/martinclaro
 */
interface PacketBuilderInterface
{
    /**
     * Build
     *
     * @return Packet
     */
    public function build();
}
