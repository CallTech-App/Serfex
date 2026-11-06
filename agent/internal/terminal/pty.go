package terminal

import (
	"os"

	"golang.org/x/sys/unix"
)

// openPTY returns a new pseudo-terminal: the side the agent reads and
// writes, and the side the shell gets as its terminal.
func openPTY() (master, slave *os.File, err error) {
	fd, err := unix.Open("/dev/ptmx", unix.O_RDWR|unix.O_NOCTTY|unix.O_CLOEXEC, 0)
	if err != nil {
		return nil, nil, err
	}
	if err := unix.IoctlSetPointerInt(fd, unix.TIOCSPTLCK, 0); err != nil {
		unix.Close(fd)
		return nil, nil, err
	}
	// The peer is opened through the master, never by its /dev/pts path,
	// so it cannot be swapped in between.
	sfd, err := ptyPeer(fd)
	if err != nil {
		unix.Close(fd)
		return nil, nil, err
	}
	// Non-blocking makes the master pollable, so a read can be cut short
	// by closing it.
	if err := unix.SetNonblock(fd, true); err != nil {
		unix.Close(fd)
		unix.Close(sfd)
		return nil, nil, err
	}
	return os.NewFile(uintptr(fd), "/dev/ptmx"), os.NewFile(uintptr(sfd), "pty"), nil
}

// ptyPeer opens the terminal side; TIOCGPTPEER takes the open flags as
// its argument.
func ptyPeer(fd int) (int, error) {
	r, _, errno := unix.Syscall(unix.SYS_IOCTL, uintptr(fd), uintptr(unix.TIOCGPTPEER), uintptr(unix.O_RDWR|unix.O_NOCTTY|unix.O_CLOEXEC))
	if errno != 0 {
		return -1, errno
	}
	return int(r), nil
}

// resize sets the terminal's size; the shell gets SIGWINCH.
func resize(master *os.File, cols, rows int) error {
	// Fd() would make the file blocking again; Control does not.
	rc, err := master.SyscallConn()
	if err != nil {
		return err
	}
	var ierr error
	if err := rc.Control(func(fd uintptr) {
		ierr = unix.IoctlSetWinsize(int(fd), unix.TIOCSWINSZ, &unix.Winsize{Col: uint16(cols), Row: uint16(rows)})
	}); err != nil {
		return err
	}
	return ierr
}
