#include <sys/types.h>
#include <sys/stat.h>
#include <sys/wait.h>
#include <sys/mount.h>
#include <sys/select.h>
#include <unistd.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <errno.h>
#include <signal.h>
#include <dirent.h>
#include <termios.h>
#include <pwd.h>
#include <grp.h>
#include <fcntl.h>
#include <utime.h>
#include <assert.h>
#include <string>
#include <map>
#include <iostream>
#include <sys/ioctl.h>
#if __linux__
#include <mntent.h>
#elif __APPLE__
#include <sys/param.h>
#include <sys/ucred.h>
#include <sys/mount.h>
#endif

#define ROOT 0

#define FLAG_CP 1

#ifndef O_PATH
#define O_PATH 0
#endif

static std::map<std::string, int> dst_table;
static std::multimap<std::string, std::string> lnk_table;
static int exit_value = 0;
static bool verbose = false;
static bool dryrun = false;
static bool makepty = false;
static bool copy_samedev = false;
static FILE* verbosefile = stdout;
static std::string linkdir;
static std::map<std::string, int> linkdir_dirtable;
static std::string dstroot;
static std::map<std::string, int> umount_table;

static int perror_fail(const char* format, const char* arg1) {
    fprintf(stderr, format, arg1, strerror(errno));
    exit_value = 1;
    return 1;
}

static const char* uid_to_name(uid_t u) {
    static uid_t old_uid = -1;
    static char buf[128];
    if (u != old_uid) {
        old_uid = u;
        if (struct passwd *pw = getpwuid(u))
            snprintf(buf, sizeof(buf), "%s", pw->pw_name);
        else
            snprintf(buf, sizeof(buf), "%u", (unsigned) u);
    }
    return buf;
}

static const char* gid_to_name(gid_t g) {
    static gid_t old_gid = -1;
    static char buf[128];
    if (g != old_gid) {
        old_gid = g;
        if (struct group *gr = getgrgid(g))
            snprintf(buf, sizeof(buf), "%s", gr->gr_name);
        else
            snprintf(buf, sizeof(buf), "%u", (unsigned) g);
    }
    return buf;
}


static int x_mkdir(const char* pathname, mode_t mode) {
    if (verbose)
        fprintf(verbosefile, "mkdir -m 0%o %s\n", mode, pathname);
    return dryrun ? 0 : mkdir(pathname, mode);
}

static int x_ensuredir(const char* pathname, mode_t mode) {
    struct stat s;
    int r = stat(pathname, &s);
    if (r == 0 && S_ISDIR(s.st_mode))
        return 0;
    else if (r != 0 && errno == ENOENT) {
        int r = x_mkdir(pathname, mode);
        return r ? r : 1;
    } else {
        if (r == 0)
            errno = ENOTDIR;
        return -1;
    }
}

static bool x_link_eexist_ok(const char* newpath) {
    // Maybe we are trying to link a file using two pathnames, where
    // an intermediate directory was a symbolic link.
    std::string dst(newpath);
    size_t npos = dst.length() + 1, slash;
    while (npos != 0
           && (slash = dst.rfind('/', npos - 1)) != std::string::npos) {
        std::string dstdir = dst.substr(0, slash);
        for (auto it = lnk_table.lower_bound(dstdir);
             it != lnk_table.end() && it->first == dstdir;
             ++it) {
            std::string lnkdst = it->second + dst.substr(slash);
            if (dst_table.find(lnkdst) != dst_table.end())
                return true;
        }
        npos = slash;
    }
    return false;
}

static int x_link(const char* oldpath, const char* newpath) {
    if (verbose)
        fprintf(verbosefile, "ln %s %s\n", oldpath, newpath);
    if (!dryrun && link(oldpath, newpath) != 0
        && (errno != EEXIST || !x_link_eexist_ok(newpath)))
        return -1;
    return 0;
}

static int x_chmod(const char* path, mode_t mode) {
    if (verbose)
        fprintf(verbosefile, "chmod 0%o %s\n", mode, path);
    if (!dryrun && chmod(path, mode) != 0)
        return perror_fail("chmod %s: %s\n", path);
    return 0;
}

static int x_lchown(const char* path, uid_t owner, gid_t group) {
    if (verbose)
        fprintf(verbosefile, "chown -h %s:%s %s\n", uid_to_name(owner), gid_to_name(group), path);
    if (!dryrun && lchown(path, owner, group) != 0)
        return perror_fail("chown %s: %s\n", path);
    return 0;
}


static __attribute__((noreturn)) void usage(void) {
    fprintf(stderr, "Usage: execjail [-n | -V] [-l SKELETONDIR] [-t] [-d] JAILDIR USER COMMAND < JAILFILES\n");
    fprintf(stderr, "       execjail -m JAILDIR NEWNAME\n");
    fprintf(stderr, "       execjail -d JAILDIR\n");
    exit(1);
}

static __attribute__((noreturn)) void perror_exit(const char* message) {
    fprintf(stderr, "%s: %s\n", message, strerror(errno));
    exit(1);
}

static void chown_recursive(char* buf, int depth, uid_t owner, gid_t group) {
    int len = strlen(buf);
    if (len == 0 || len == PATH_MAX - 1) {
        fprintf(stderr, "%s: Bad pathname\n", buf);
        exit(1);
    }
    if (buf[len - 1] != '/') {
        strcpy(&buf[len], "/");
        ++len;
    }

    typedef std::pair<uid_t, gid_t> ug_t;
    std::map<std::string, ug_t>* home_map = NULL;
    if (depth == 1 && len >= 6 && memcmp(&buf[len - 6], "/home/", 6) == 0) {
        home_map = new std::map<std::string, ug_t>;
        setpwent();
        while (struct passwd* pw = getpwent()) {
            std::string name;
            if (pw->pw_dir && strncmp(pw->pw_dir, "/home/", 6) == 0
                && strchr(pw->pw_dir + 6, '/') == NULL)
                name = pw->pw_dir + 6;
            else
                name = pw->pw_name;
            (*home_map)[name] = ug_t(pw->pw_uid, pw->pw_gid);
        }
    }

    DIR* dir = opendir(buf);
    if (!dir) {
        if (errno == ENOENT && depth == 0 && dryrun)
            return;
        perror_exit(buf);
    }

    struct dirent* de;
    uid_t u;
    gid_t g;

    while ((de = readdir(dir))) {
        if (strcmp(de->d_name, ".") == 0 || strcmp(de->d_name, "..") == 0)
            continue;

        // construct name
        int namelen = strlen(de->d_name);
        if (len + namelen + 1 >= PATH_MAX) {
            fprintf(stderr, "%.*s%s: Name too long\n", len, buf, de->d_name);
            exit(1);
        }
        memcpy(&buf[len], de->d_name, namelen + 1);

        // don't follow symbolic links
        if (de->d_type == DT_LNK) {
            if (x_lchown(buf, owner, group))
                perror_exit(buf);
            continue;
        }

        // change its uid/gid
        u = owner, g = group;
        if (home_map) {
            auto it = home_map->find(de->d_name);
            if (it != home_map->end())
                u = it->second.first, g = it->second.second;
        }
        if (x_lchown(buf, u, g))
            perror_exit(buf);

        // recurse
        if (de->d_type == DT_DIR)
            chown_recursive(buf, depth + 1, u, g);
    }

    closedir(dir);
    delete home_map;
}


// jailmaking

struct mountslot {
    std::string fsname;
    std::string type;
    std::string alloptions;
    unsigned long opts;
    std::string data;
    bool allowed;
};
typedef std::map<std::string, mountslot> mount_table_type;
mount_table_type mount_table;

static bool allow_mount(const char* dest, const mountslot& ms) {
    return ((strcmp(dest, "/proc") == 0 && ms.type == "proc")
            || (strcmp(dest, "/sys") == 0 && ms.type == "sysfs")
            || (strcmp(dest, "/dev") == 0 && ms.type == "udev")
            || (strcmp(dest, "/dev/pts") == 0 && ms.type == "devpts"));
}

#if __linux__
#define MFLAG(x) MS_ ## x
#elif __APPLE__
#define MFLAG(x) MNT_ ## x
#endif

struct mountarg {
    const char *name;
    int value;
};
static const mountarg mountargs[] = {
    { ",nosuid,", MFLAG(NOSUID) },
    { ",nodev,", MFLAG(NODEV) },
    { ",noexec,", MFLAG(NOEXEC) },
    { ",ro,", MFLAG(RDONLY) },
    { ",rw,", 0 },
#if __linux__
    { ",noatime,", MS_NOATIME },
    { ",nodiratime,", MS_NODIRATIME },
#ifdef MS_RELATIME
    { ",relatime,", MS_RELATIME },
#endif
#ifdef MS_STRICTATIME
    { ",strictatime,", MS_STRICTATIME },
#endif
#endif
    { NULL, 0 }
};

static int populate_mount_table() {
#if __linux__
    FILE* f = setmntent("/proc/mounts", "r");
    if (!f)
        return perror_fail("open %s: %s\n", "/proc/mounts");
    while (struct mntent* me = getmntent(f)) {
        char options[BUFSIZ], *options_pos;
        snprintf(options, sizeof(options), ",%s,", me->mnt_opts);
        unsigned long opts = 0;
        for (const mountarg *ma = mountargs; ma->name; ++ma)
            if ((options_pos = strstr(options, ma->name))) {
                opts |= ma->value;
                char* post = options_pos + strlen(ma->name) - 1;
                memmove(options_pos, post, strlen(post) + 1);
            }
        int l;
        while ((l = strlen(options)) > 1 && options[l - 1] == ',')
            options[l - 1] = '\0';
        mountslot ms{me->mnt_fsname, me->mnt_type, me->mnt_opts,
                opts, &options[1], false};
        ms.allowed = allow_mount(me->mnt_dir, ms);
        mount_table[me->mnt_dir] = ms;
    }
    fclose(f);
    return 0;
#elif __APPLE__
    struct statfs* mntbuf;
    int nmntbuf = getmntinfo(&mntbuf, MNT_NOWAIT);
    for (struct statfs* me = mntbuf; me != mntbuf + nmntbuf; ++me) {
        mountslot ms{me->f_mntfromname, me->f_fstypename, std::string(),
                me->f_flags, std::string(), false};
        for (const mountarg* ma = mountargs; ma->name; ++ma)
            if (ma->value & me->f_flags)
                ms.alloptions += (ms.alloptions.empty() ? "" : ",")
                    + std::string(&ma->name[1], strlen(ma->name) - 2);
        ms.allowed = allow_mount(me->f_mntonname, ms);
        mount_table[me->f_mntonname] = ms;
    }
    return 0;
#endif
}

#if __APPLE__
int mount(const char*, const char* target, const char* fstype,
          unsigned long flags, const void*) {
    return ::mount(fstype, target, flags, NULL);
}

int umount(const char* dir) {
    return ::unmount(dir, 0);
}
#endif

static int handle_mount(const mountslot& ms, std::string dst) {
    if (verbose)
        fprintf(verbosefile, "mount -i -n -t %s%s%s %s %s\n",
                ms.type.c_str(), ms.alloptions.empty() ? "" : " -o ",
                ms.alloptions.c_str(), ms.fsname.c_str(), dst.c_str());
    if (!dryrun && mount(ms.fsname.c_str(), dst.c_str(), ms.type.c_str(),
                         ms.opts,
                         ms.data.empty() ? NULL : ms.data.c_str()) != 0)
        return perror_fail("mount %s: %s\n", dst.c_str());
    return 0;
}

static int handle_umount(const mount_table_type::iterator& it) {
    if (verbose)
        fprintf(verbosefile, "umount -i -n %s\n", it->first.c_str());
    if (!dryrun && umount(it->first.c_str()) != 0) {
        fprintf(stderr, "umount %s: %s\n", it->first.c_str(), strerror(errno));
        exit(1);
    }
    if (dryrun)
        umount_table[it->first.c_str()] = 1;
    return 0;
}

static int handle_copy(const std::string& src, const std::string& dst,
                       bool check_parents, int flags,
                       dev_t jaildev, mode_t* srcmode);

static void handle_symlink_dst(std::string src, std::string dst,
                               std::string lnk, dev_t jaildev)
{
    std::string dst_lnkin = dst;

    // expand `lnk` into `dst`
    if (lnk[0] == '/') {
        src = lnk;
        dst = dstroot + lnk;
    } else {
        while (1) {
            if (src.length() == 1) {
            give_up:
                return;
            }
            size_t srcslash = src.rfind('/', src.length() - 2),
                dstslash = dst.rfind('/', dst.length() - 2);
            if (srcslash == std::string::npos || dstslash == std::string::npos
                || dstslash < dstroot.length())
                goto give_up;
            src = src.substr(0, srcslash + 1);
            dst = dst.substr(0, dstslash + 1);
            if (lnk.length() > 3 && lnk[0] == '.' && lnk[1] == '.'
                && lnk[2] == '/')
                lnk = lnk.substr(3);
            else
                break;
        }
        src += lnk;
        dst += lnk;
    }

    if (dst.substr(dstroot.length(), 6) != "/proc/") {
        mode_t srcmode;
        int r = handle_copy(src, dst, true, 0, jaildev, &srcmode);
        // remember directory-level symbolic links
        if (r == 0 && S_ISDIR(srcmode)) {
            lnk_table.insert(std::make_pair(dst, dst_lnkin));
            lnk_table.insert(std::make_pair(dst_lnkin, dst));
        }
    }
}

static int copy_for_xdev_link(const std::string& src, const std::string& lnk) {
    // create superdirectories
    size_t pos = linkdir.length() - 1;
    while ((pos = lnk.find('/', pos + 1)) != std::string::npos) {
        std::string lnksuper = lnk.substr(0, pos);
        if (linkdir_dirtable.find(lnksuper) == linkdir_dirtable.end()) {
            struct stat dst;
            if (lstat(lnksuper.c_str(), &dst) != 0) {
                if (errno != ENOENT)
                    return perror_fail("lstat %s: %s\n", lnksuper.c_str());
                if (x_mkdir(lnksuper.c_str(), 0770) != 0 && errno != EEXIST)
                    return perror_fail("mkdir %s: %s\n", lnksuper.c_str());
            } else if (!S_ISDIR(dst.st_mode))
                return perror_fail("lstat %s: Not a directory\n", lnksuper.c_str());
            linkdir_dirtable[lnksuper] = 1;
        }
    }

    // run /bin/cp -p
    if (verbose)
        fprintf(verbosefile, "cp -p %s %s\n", src.c_str(), lnk.c_str());
    if (dryrun)
        return 0;

    pid_t child = fork();
    if (child == 0) {
        const char* args[6] = {
            "/bin/cp", "-p", src.c_str(), lnk.c_str(), NULL
        };
        execv("/bin/cp", (char**) args);
        exit(1);
    } else if (child < 0)
        return perror_fail("%s: %s\n", "fork");

    int status;
    pid_t wait_child = waitpid(child, &status, 0);
    if (wait_child == child && WIFEXITED(status) && WEXITSTATUS(status) == 0)
        return 0;
    else if (wait_child == child && WIFEXITED(status))
        return perror_fail("/bin/cp %s: Bad exit status\n", lnk.c_str());
    else
        return perror_fail("/bin/cp %s: Did not exit\n", lnk.c_str());
}

static int handle_xdev_link(const std::string& src, const std::string& dst,
                            const struct stat& st) {
    struct stat lst;
    std::string lnk = linkdir + src;

    int r = lstat(lnk.c_str(), &lst);
    if (r != 0
        || lst.st_mode != st.st_mode
        || lst.st_uid != st.st_uid
        || lst.st_gid != st.st_gid
        || lst.st_size != st.st_size
        || lst.st_mtime != st.st_mtime) {
        if (r == 0 && S_ISDIR(lst.st_mode))
            return perror_fail("%s: Is a directory\n", lnk.c_str());
        if (copy_for_xdev_link(src, lnk))
            return 1;
    }

    if (x_link(lnk.c_str(), dst.c_str()) != 0)
        return perror_fail("link %s: %s\n", (dst+lnk).c_str());
    return 0;
}

static const char* dev_name(mode_t m, dev_t d) {
    static char buf[128];
    if (S_ISCHR(m))
        snprintf(buf, sizeof(buf), "c %d %d", major(d), minor(d));
    else if (S_ISBLK(m))
        snprintf(buf, sizeof(buf), "b %d %d", major(d), minor(d));
    else if (S_ISFIFO(m))
        return "p";
    else
        snprintf(buf, sizeof(buf), "%u %u", (unsigned) m, (unsigned) d);
    return buf;
}

static int handle_copy(const std::string& src, const std::string& dst,
                       bool check_parents, int flags,
                       dev_t jaildev, mode_t* srcmode) {
    if (dst_table.find(dst) != dst_table.end())
        return 1;
    dst_table[dst] = 1;

    struct stat ss, ds;

    if (check_parents) {
        size_t last_slash = dst.rfind('/');
        if (last_slash != 0
            && last_slash != std::string::npos
            && last_slash != dst.length() - 1) {
            size_t last_nchars = dst.length() - last_slash;
            if (src.length() > last_nchars
                && src.substr(src.length() - last_nchars) == dst.substr(dst.length() - last_nchars)) {
                std::string dstdir = dst.substr(0, last_slash);
                if (lstat(dstdir.c_str(), &ss) == -1 && errno == ENOENT)
                    handle_copy(src.substr(0, src.length() - last_nchars),
                                dst.substr(0, dst.length() - last_nchars),
                                true, 0, jaildev, NULL);
            }
        }
    }

    if (lstat(src.c_str(), &ss) != 0)
        return perror_fail("lstat %s: %s\n", src.c_str());
    if (srcmode)
        *srcmode = ss.st_mode;
    ds.st_uid = ds.st_gid = ROOT;

    if (S_ISREG(ss.st_mode) && !copy_samedev && !(flags & FLAG_CP)
        && ss.st_dev == jaildev) {
        if (x_link(src.c_str(), dst.c_str()) != 0)
            return perror_fail("link %s: %s\n", (dst+src).c_str());
        ds = ss;
    } else if (S_ISREG(ss.st_mode)
               || (S_ISLNK(ss.st_mode) && (flags & FLAG_CP))) {
        errno = EXDEV;
        if (linkdir.empty() || handle_xdev_link(src, dst, ss) != 0)
            return perror_fail("link %s: %s\n", dst.c_str());
        ds = ss;
    } else if (S_ISDIR(ss.st_mode)) {
        // allow setuid/setgid bits
        // allow the presence of a different directory
        mode_t perm = ss.st_mode & (S_ISUID | S_ISGID | S_IRWXU | S_IRWXG | S_IRWXO);
        if (x_mkdir(dst.c_str(), perm) == 0)
            ds.st_mode = perm | S_IFDIR;
        else if (lstat(dst.c_str(), &ds) != 0)
            return perror_fail("lstat %s: %s\n", dst.c_str());
        else if (!S_ISDIR(ds.st_mode))
            return perror_fail("lstat %s: Not a directory\n", dst.c_str());
    } else if (S_ISCHR(ss.st_mode) || S_ISBLK(ss.st_mode)) {
        ss.st_mode &= (S_IFREG | S_IFCHR | S_IFBLK | S_IFIFO | S_IFSOCK | S_ISUID | S_ISGID | S_IRWXU | S_IRWXG | S_IRWXO);
        if (verbose)
            fprintf(verbosefile, "mknod -m 0%o %s %s\n", ss.st_mode, dst.c_str(), dev_name(ss.st_mode, ss.st_rdev));
        if (!dryrun && mknod(dst.c_str(), ss.st_mode, ss.st_rdev) != 0)
            return perror_fail("mknod %s: %s\n", dst.c_str());
        ds.st_mode = ss.st_mode;
    } else if (S_ISLNK(ss.st_mode)) {
        char lnkbuf[4096];
        ssize_t r = readlink(src.c_str(), lnkbuf, sizeof(lnkbuf));
        if (r == -1)
            return perror_fail("readlink %s: %s\n", src.c_str());
        else if (r == sizeof(lnkbuf))
            return perror_fail("%s: Symbolic link too long\n", src.c_str());
        lnkbuf[r] = 0;
        if (verbose)
            fprintf(verbosefile, "ln -s %s %s\n", lnkbuf, dst.c_str());
        if (!dryrun && symlink(lnkbuf, dst.c_str()) != 0)
            return perror_fail("symlink %s: %s\n", src.c_str());
        ds.st_mode = ss.st_mode;
        handle_symlink_dst(src, dst, std::string(lnkbuf), jaildev);
    } else
        return perror_fail("%s: Odd file type\n", src.c_str());

    // XXX preserve sticky bits/setuid/setgid?
    if (ds.st_mode != ss.st_mode
        && x_chmod(dst.c_str(), ss.st_mode))
        return 1;
    if ((ds.st_uid != ss.st_uid || ds.st_gid != ss.st_gid)
        && x_lchown(dst.c_str(), ss.st_uid, ss.st_gid))
        return 1;

    if (S_ISDIR(ss.st_mode)) {
        auto it = mount_table.find(src.c_str());
        if (it != mount_table.end() && it->second.allowed)
            return handle_mount(it->second, dst);
    }

    return 0;
}

static int construct_jail(std::string jaildir, dev_t jaildev) {
    dstroot = jaildir;
    while (dstroot.length() > 1 && dstroot[dstroot.length() - 1] == '/')
        dstroot = dstroot.substr(0, dstroot.length() - 1);

    // prepare root
    if (x_chmod(dstroot.c_str(), 0755)
        || x_lchown(dstroot.c_str(), 0, 0))
        return 1;
    dst_table[dstroot + "/"] = 1;

    // Mounts
    populate_mount_table();
#if __linux__
    {
        std::string proc("/proc");
        handle_copy(proc, dstroot + proc, true, 0, jaildev, NULL);
        if (makepty) {
            std::string devpts("/dev/pts");
            handle_copy(devpts, dstroot + devpts, true, 0, jaildev, NULL);
            std::string devptmx("/dev/ptmx");
            handle_copy(devptmx, dstroot + devptmx, true, 0, jaildev, NULL);
        }
    }
#endif

    // Read a line at a time
    std::string cursrcdir("/"), curdstdir(dstroot);

    char buf[BUFSIZ];
    while (fgets(buf, BUFSIZ, stdin)) {
        int l = strlen(buf);
        while (l > 0 && isspace((unsigned char) buf[l-1]))
            buf[--l] = 0;
        if (l == 0)
            continue;

        // 'directory:'
        if (buf[l - 1] == ':') {
            if (l == 2 && buf[0] == '.')
                cursrcdir = std::string("/");
            else if (l > 2 && buf[0] == '.' && buf[1] == '/')
                cursrcdir = std::string(buf + 1, buf + l - 1);
            else
                cursrcdir = std::string(buf, buf + l - 1);
            if (cursrcdir[0] != '/')
                cursrcdir = std::string("/") + cursrcdir;
            while (cursrcdir.length() > 1 && cursrcdir[cursrcdir.length() - 1] == '/' && cursrcdir[cursrcdir.length() - 2] == '/')
                cursrcdir = cursrcdir.substr(0, cursrcdir.length() - 1);
            if (cursrcdir[cursrcdir.length() - 1] != '/')
                cursrcdir += '/';
            curdstdir = dstroot + cursrcdir;
            continue;
        }

        // '[FLAGS]'
        int flags = 0;
        if (buf[l - 1] == ']') {
            for (--l; l > 0 && buf[l-1] != '['; --l)
                /* do nothing */;
            if (l == 0)
                continue;
            char* p;
            if ((p = strstr(&buf[l], "cp"))
                && (p[-1] == '[' || p[-1] == ',')
                && (p[2] == ']' || p[2] == ','))
                flags |= FLAG_CP;
            do {
                buf[--l] = 0;
            } while (l > 0 && isspace((unsigned char) buf[l-1]));
        }

        std::string src, dst;
        char* arrow = strstr(buf, " <- ");
        if (buf[0] == '/' && arrow) {
            src = std::string(arrow + 4);
            dst = curdstdir + std::string(buf, arrow);
        } else if (buf[0] == '/') {
            src = std::string(buf);
            dst = curdstdir + std::string(buf, buf + l);
        } else if (arrow) {
            src = std::string(arrow + 4, buf + l);
            dst = curdstdir + std::string(buf, arrow);
        } else {
            src = cursrcdir + std::string(buf, buf + l);
            dst = curdstdir + std::string(buf, buf + l);
        }
        handle_copy(src, dst, buf[0] == '/', flags, jaildev, NULL);
    }

    return exit_value;
}


// main program

static int check_filename(const char *name, int allow_slash,
                          int allow_absolute) {
    const char *allowed_chars = "/0123456789-._ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz~";
    const char *name2, *dotdot;

    if (!allow_slash)
        ++allowed_chars;
    if (strspn(name, allowed_chars) != strlen(name) || name[0] == '~')
        return 0;

    name2 = name;
    while ((dotdot = strstr(name2, ".."))) {
        if ((dotdot == name || dotdot[-1] == '/')
            && (dotdot[2] == 0 || dotdot[2] == '/'))
            return 0;
        name2 = dotdot + 2;
    }

    if (!allow_absolute && name[0] == '/')
        return 0;

    if (strlen(name) >= 1024)
        return 0;

    return 1;
}

static dev_t closest_ancestor_dev(std::string dir) {
    while (1) {
        struct stat s;
        if (lstat(dir.c_str(), &s) == 0)
            return s.st_dev;
        dir = dir.substr(0, dir.rfind('/'));
        if (dir.empty())
            dir = "/";
    }
}

static std::string absolute(const std::string& dir) {
    if (!dir.empty() && dir[0] == '/')
        return dir;
    FILE* p = popen("pwd", "r");
    char buf[BUFSIZ], crap[1];
    size_t buflen;
    if (fgets(buf, BUFSIZ, p)
        && (!fgets(crap, 1, p) || crap[0] == '\0')
        && (buflen = strnlen(buf, BUFSIZ)) < BUFSIZ) {
        pclose(p);
        while (isspace((unsigned char) buf[buflen - 1]))
            buf[--buflen] = '\0';
        while (buflen > 0 && buf[buflen - 1] == '/')
            buf[--buflen] = '\0';
        return std::string(buf) + std::string("/") + dir;
    } else {
        fprintf(stderr, "pwd: Bogus absolute path\n");
        exit(1);
    }
}

static void x_rm_rf_under(int dirfd, std::string dirname) {
    if (dirname.length() == 0 || dirname[dirname.length() - 1] != '/')
        dirname += "/";
    int defd = openat(dirfd, ".", O_RDONLY);
    if (defd == -1) {
        fprintf(stderr, "%s: %s\n", dirname.c_str(), strerror(errno));
        exit(1);
    }
    DIR* dir = fdopendir(defd);
    if (!dir) {
        fprintf(stderr, "%s: %s\n", dirname.c_str(), strerror(errno));
        exit(1);
    }
    while (struct dirent* de = readdir(dir)) {
        // XXX check file system type?
        if (de->d_type == DT_DIR) {
            if (strcmp(de->d_name, ".") == 0 || strcmp(de->d_name, "..") == 0)
                continue;
            std::string next_dirname = dirname + std::string(de->d_name);
            if (umount_table.find(next_dirname) != umount_table.end())
                continue;
            int next_dirfd = openat(dirfd, de->d_name, O_PATH);
            if (dirfd == -1) {
                fprintf(stderr, "%s: %s\n", next_dirname.c_str(), strerror(errno));
                exit(1);
            }
            x_rm_rf_under(next_dirfd, next_dirname);
            close(next_dirfd);
        }
        const char* op = de->d_type == DT_DIR ? "rmdir" : "rm";
        if (verbose)
            fprintf(verbosefile, "%s %s%s\n", op, dirname.c_str(), de->d_name);
        if (!dryrun && unlinkat(dirfd, de->d_name, de->d_type == DT_DIR ? AT_REMOVEDIR : 0) != 0) {
            fprintf(stderr, "%s %s%s: %s\n", op, dirname.c_str(), de->d_name, strerror(errno));
            exit(1);
        }
    }
    closedir(dir);
}

static void handle_child(pid_t child, int ptymaster) {
    // blocking reads please (well, block for up to 0.5sec)
    // the 0.5sec wait means we avoid long race conditions
    struct termios tty;
    if (tcgetattr(ptymaster, &tty) >= 0) {
        tty.c_cc[VMIN] = 1;
        tty.c_cc[VTIME] = 5;
        tcsetattr(ptymaster, TCSANOW, &tty);
    }

    char buf[16384];
    int status;
    fflush(stdout);

    while (1) {
        ssize_t nr = read(ptymaster, buf, sizeof(buf));
        if (nr != 0 && nr != -1) {
            size_t nw = fwrite(buf, 1, nr, stdout);
            if (nw != (size_t) nr)
                exit(1);
            fflush(stdout);
            continue;           // read until no more to read
        }

        // has child died?
        int r = waitpid(child, &status, WNOHANG);
        if (r == child && WIFEXITED(status))
            exit(WEXITSTATUS(status));
        else if (r == child)
            exit(0);

        // if child has not died, and read produced error, report it
        if (nr == -1 && (errno != EINTR && errno != EAGAIN && errno != EIO))
            perror_exit("read");
    }
}

static std::string take_word(const std::string& str, size_t& pos) {
    while (pos < str.length() && str[pos] != '\n' && isspace((unsigned char) str[pos]))
        ++pos;
    if (pos < str.length() && !isspace((unsigned char) str[pos])) {
        size_t spos = pos;
        while (pos < str.length() && !isspace((unsigned char) str[pos]))
            ++pos;
        return str.substr(spos, pos - spos);
    } else
        return std::string();
}

static bool check_shell(const char* shell) {
    bool found = false;
    char* sh;
    while (!found && (sh = getusershell()))
        found = strcmp(sh, shell) == 0;
    endusershell();
    return found;
}

int main(int argc, char** argv) {
    // parse arguments
    bool dokill = false, dolive = false, domove = false;
    std::string jaildir;

 parse_argument:
    if (argc >= 2 && strcmp(argv[1], "-d") == 0) {
        dokill = true;
        argv += 1, argc -= 1;
        goto parse_argument;
    } else if (argc >= 2 && strcmp(argv[1], "-f") == 0) {
        // XXX force = true;
        argv += 1, argc -= 1;
        goto parse_argument;
    } else if (argc >= 2 && strcmp(argv[1], "-V") == 0) {
        verbose = true;
        argv += 1, argc -= 1;
        goto parse_argument;
    } else if (argc >= 3 && strcmp(argv[1], "-l") == 0) {
        linkdir = argv[2];
        while (!linkdir.empty() && linkdir[linkdir.length() - 1] == '/')
            linkdir = linkdir.substr(0, linkdir.length() - 1);
        argv += 2, argc -= 2;
        goto parse_argument;
    } else if (argc >= 2 && strcmp(argv[1], "-n") == 0) {
        verbose = dryrun = true;
        argv += 1, argc -= 1;
        goto parse_argument;
    } else if (argc >= 2 && strcmp(argv[1], "-t") == 0) {
        makepty = true;
        argv += 1, argc -= 1;
        goto parse_argument;
    } else if (argc >= 2 && strcmp(argv[1], "-s") == 0) {
        dolive = true;
        argv += 1, argc -= 1;
        goto parse_argument;
    } else if (argc >= 2 && strcmp(argv[1], "-m") == 0) {
        domove = true;
        argv += 1, argc -= 1;
        goto parse_argument;
    } else if (argc >= 2 && argv[1][0] == '-')
        usage();

    if (verbose && !dryrun)
        verbosefile = stderr;
    bool doexec = (!dokill || argc == 4) && !domove;
    if ((doexec && argc != 4)
        || (dokill && !doexec && argc != 2)
        || (domove && argc != 3)
        || (domove && (makepty || dolive || dokill))
        || !argv[1][0])
        usage();

    // open tty
    int caller_tty = -1;
    if (dolive)
        caller_tty = open("/dev/tty", O_RDWR);

    // track whether real UID/GID was root
    bool was_root = getuid() == ROOT && getgid() == ROOT;
    (void) was_root; /* don't need this right now */

    // escalate so that the real (not just effective) UID/GID is root. this is
    // so that the system processes will execute as root
    if (!dryrun && setgid(ROOT) < 0)
        perror_exit("setgid");
    if (!dryrun && setuid(ROOT) < 0)
        perror_exit("setuid");

    // check the jail directory:
    // - no special characters
    // - path has no symlinks
    // - at least one superdir has a file `JAIL61` owned by root
    //   containing `allowjail`
    // - no superdir has a file `JAIL61` not owned by root,
    //   or containing `nojail`
    // - everything above that dir is owned by by root
    // - stuff below the dir containing `JAIL61` dynamically created
    //   if necessary
    // - try to eliminate TOCTTOU
    jaildir = absolute(argv[1]);
    const char* jailstr = jaildir.c_str();
    if (!check_filename(jailstr, 1, 1) || jailstr[0] != '/') {
        fprintf(stderr, "%s: Bad characters in filename\n", jailstr);
        exit(1);
    }

    int jailrootfd = -1, jailparentfd = -1;
    std::string jailparent, jailcomponent, jailsuperdir;
    {
        size_t last_pos = 0;
        bool found_superdir = false;
        while (last_pos != jaildir.length()) {
            // extract component
            size_t next_pos = last_pos;
            while (next_pos && next_pos < jaildir.length() && jaildir[next_pos] != '/')
                ++next_pos;
            if (!next_pos)
                ++next_pos;
            jailparent = jaildir.substr(0, last_pos);
            jailcomponent = jaildir.substr(last_pos, next_pos - last_pos);
            std::string superdir = jaildir.substr(0, next_pos);
            last_pos = next_pos;
            while (last_pos != jaildir.length() && jaildir[last_pos] == '/')
                ++last_pos;

            // open it and swap it in
            int next_jailrootfd = openat(jailrootfd, jailcomponent.c_str(), O_PATH | O_CLOEXEC | O_NOFOLLOW);
            if (next_jailrootfd == -1 && found_superdir && doexec && errno == ENOENT) {
                if (mkdirat(jailrootfd, jailcomponent.c_str(), 0755) != 0) {
                    fprintf(stderr, "mkdir %s: %s\n", superdir.c_str(), strerror(errno));
                    exit(1);
                }
                next_jailrootfd = openat(jailrootfd, jailcomponent.c_str(), O_PATH | O_CLOEXEC | O_NOFOLLOW);
            }
            if (next_jailrootfd == -1) {
                fprintf(stderr, "%s: %s\n", superdir.c_str(), strerror(errno));
                exit(1);
            }
            close(jailparentfd);
            jailparentfd = jailrootfd;
            jailrootfd = next_jailrootfd;

            // stat it
            struct stat s;
            if (fstat(jailrootfd, &s) != 0) {
                fprintf(stderr, "%s: %s\n", superdir.c_str(), strerror(errno));
                exit(1);
            }
            if (!S_ISDIR(s.st_mode)) {
                fprintf(stderr, "%s: Not a directory\n", superdir.c_str());
                exit(1);
            } else if (s.st_uid != ROOT && !found_superdir) {
                fprintf(stderr, "%s: Not owned by root\n", superdir.c_str());
                exit(1);
            }

            // check for "JAIL61" allowance
            if (!found_superdir && jailparent.length()) {
                int jail61f = openat(jailrootfd, "JAIL61", O_RDONLY | O_NOFOLLOW);
                if (jail61f == -1 && errno != ENOENT && errno != ELOOP) {
                    fprintf(stderr, "%s/JAIL61: %s\n", superdir.c_str(), strerror(errno));
                    exit(1);
                }
                if (jail61f != -1) {
                    struct stat s;
                    if (fstat(jail61f, &s) != 0) {
                        fprintf(stderr, "%s/JAIL61: %s\n", superdir.c_str(), strerror(errno));
                        exit(1);
                    } else if (s.st_uid != ROOT
                               || (s.st_gid != ROOT && (s.st_mode & S_IWGRP))
                               || (s.st_mode & S_IWOTH)) {
                        fprintf(stderr, "%s/JAIL61: Ignoring, writable by non-root\n", superdir.c_str());
                        close(jail61f);
                        jail61f = -1;
                    }
                }
                if (jail61f != -1) {
                    char buf[8192];
                    ssize_t nr = read(jail61f, buf, sizeof(buf));

                    std::string str(buf, nr < 0 ? 0 : nr);
                    size_t pos = 0;
                    while (pos < str.length()) {
                        std::string word1 = take_word(str, pos);
                        std::string word2 = take_word(str, pos);
                        while (take_word(str, pos).length())
                            /* do nothing */;
                        while (pos < str.length() && str[pos] == '\n')
                            ++pos;

                        if (word2.length() && word2[word2.length() - 1] != '/')
                            word2 += '/';
                        bool dirmatch = word2.length() && jaildir.substr(0, word2.length()) == word2;
                        if (word1 == "nojail" && !word2.length()) {
                            fprintf(stderr, "%s/JAIL61: Jails are not allowed under here\n", superdir.c_str());
                            exit(1);
                        } else if (word1 == "nojail" && dirmatch) {
                            fprintf(stderr, "%s/JAIL61: Jails are not allowed under %s\n", superdir.c_str(), word2.c_str());
                            exit(1);
                        } else if (word1 == "allowjail" && dirmatch) {
                            found_superdir = true;
                            jailsuperdir = word2;
                        }
                    }

                    close(jail61f);
                }
            }
        }
        if (!found_superdir) {
            fprintf(stderr, "%s: No `JAIL61` above here contains `allowjail %s`\n", jailstr, jaildir.c_str());
            exit(1);
        }
    }

    // parse user
    uid_t owner = ROOT;
    gid_t group = ROOT;
    std::string owner_home;
    std::string owner_sh = "/bin/sh";
    if (doexec) {
        const char* owner_name = argv[2];
        if (strlen(owner_name) >= 1024) {
            fprintf(stderr, "%s: Username too long\n", owner_name);
            exit(1);
        }

        struct passwd* pwnam = getpwnam(owner_name);
        if (!pwnam) {
            fprintf(stderr, "%s: No such user\n", owner_name);
            exit(1);
        }

        owner = pwnam->pw_uid;
        group = pwnam->pw_gid;
        if (strcmp(pwnam->pw_dir, "/") == 0)
            owner_home = "/home/nobody";
        else if (strncmp(pwnam->pw_dir, "/home/", 6) == 0)
            owner_home = pwnam->pw_dir;
        else {
            fprintf(stderr, "%s: Home directory %s not under /home\n", owner_name, pwnam->pw_dir);
            exit(1);
        }

        if (strcmp(pwnam->pw_shell, "/bin/bash") == 0
            || strcmp(pwnam->pw_shell, "/bin/sh") == 0
            || check_shell(pwnam->pw_shell))
            owner_sh = pwnam->pw_shell;
        else {
            fprintf(stderr, "%s: Shell %s not allowed by /etc/shells\n", owner_name, pwnam->pw_shell);
            exit(1);
        }

        if (owner == ROOT) {
            fprintf(stderr, "%s: Jail user cannot be root\n", owner_name);
            exit(1);
        }
    }

    // move the sandbox if asked
    if (domove) {
        if (!check_filename(argv[2], 1, 1)) {
            fprintf(stderr, "%s: Bad characters in move destination\n", argv[2]);
            exit(1);
        }
        std::string newpath = absolute(argv[2]);
        if (newpath.length() <= jailsuperdir.length()
            || newpath.substr(0, jailsuperdir.length()) != jailsuperdir) {
            fprintf(stderr, "%s: Not a subdirectory of %s\n", newpath.c_str(), jailsuperdir.c_str());
            exit(1);
        }

        // allow second argument to be a directory
        struct stat s;
        if (stat(newpath.c_str(), &s) == 0 && S_ISDIR(s.st_mode)) {
            if (newpath[newpath.length() - 1] != '/')
                newpath += "/";
            newpath += jailcomponent;
        }

        if (verbose)
            fprintf(verbosefile, "mv %s%s %s\n", jailparent.c_str(), jailcomponent.c_str(), newpath.c_str());
        if (!dryrun && renameat(jailparentfd, jailcomponent.c_str(), jailparentfd, newpath.c_str()) != 0) {
            fprintf(stderr, "mv %s%s %s: %s\n", jailparent.c_str(), jailcomponent.c_str(), newpath.c_str(), strerror(errno));
            exit(1);
        }
        exit(0);
    }

    // kill the sandbox if asked
    if (dokill) {
        // unmount EVERYTHING mounted in the jail!
        // INCLUDING MY HOME DIRECTORY
        if (jaildir[jaildir.length() - 1] != '/')
            jaildir += "/";
        populate_mount_table();
        for (auto it = mount_table.begin(); it != mount_table.end(); ++it)
            if (it->first.length() >= jaildir.length()
                && memcmp(it->first.data(), jaildir.data(),
                          jaildir.length()) == 0)
                handle_umount(it);
        // remove the jail
        x_rm_rf_under(jailrootfd, jaildir);
        if (!doexec) {
            jaildir = jaildir.substr(0, jaildir.length() - 1);
            if (verbose)
                fprintf(verbosefile, "rmdir %s\n", jaildir.c_str());
            if (!dryrun && unlinkat(jailparentfd, jailcomponent.c_str(), AT_REMOVEDIR) != 0) {
                fprintf(stderr, "rmdir %s: %s\n", jaildir.c_str(), strerror(errno));
                exit(1);
            }
        }
        if (!doexec)
            exit(0);
    }

    // check link directory
    if (!linkdir.empty() && x_ensuredir(linkdir.c_str(), 0755) < 0)
        perror_exit(linkdir.c_str());
    if (!linkdir.empty())
        linkdir = absolute(linkdir);
    else
        copy_samedev = false;

    // stdin should be a file list
    if (isatty(0)) {
        fprintf(stderr, "stdin: Is a tty\n");
        exit(1);
    }

    // set ownership
    dev_t jaildev = closest_ancestor_dev(jaildir);
    char buf[8192];
    strcpy(buf, jaildir.c_str());
    chown_recursive(buf, 0, ROOT, ROOT);

    // construct the jail
    mode_t old_umask = umask(0);
    if (construct_jail(jaildir, jaildev) != 0)
        exit(1);
    umask(old_umask);

    // create the home directory
    if (argc >= 2) {
        if (x_ensuredir((jaildir + "/home").c_str(), 0755) < 0)
            perror_exit((jaildir + "/home").c_str());
        std::string jailhome = jaildir + owner_home;
        int r = x_ensuredir(jailhome.c_str(), 0700);
        if (r < 0
            || (r > 0 && x_lchown(jailhome.c_str(), owner, group)))
            perror_exit(jailhome.c_str());
    }

    // maybe execute a command in the jail
    if (argc == 4) {
        // enter the jail
        if (verbose)
            fprintf(verbosefile, "cd %s\n", jaildir.c_str());
        if (!dryrun && chdir(jaildir.c_str()) != 0)
            perror_exit(jaildir.c_str());
        if (verbose)
            fprintf(verbosefile, "chroot .\n");
        if (!dryrun && chroot(".") != 0)
            perror_exit("chroot");

        // reduce privileges permanently
        if (verbose)
            fprintf(verbosefile, "su %s\n", uid_to_name(owner));
        if (!dryrun && setgid(group) != 0)
            perror_exit("setgid");
        if (!dryrun && setuid(owner) != 0)
            perror_exit("setuid");

        // create a pty
        int ptymaster = -1;
        char* ptyslavename = NULL;
        if (!dryrun && makepty) {
            if ((ptymaster = posix_openpt(O_RDWR)) == -1)
                perror_exit("posix_openpt");
            if (grantpt(ptymaster) == -1)
                perror_exit("grantpt");
            if (unlockpt(ptymaster) == -1)
                perror_exit("unlockpt");
            if ((ptyslavename = ptsname(ptymaster)) == NULL)
                perror_exit("ptsname");
        }
        if (makepty && verbose)
            fprintf(verbosefile, "make-pty %s\n", ptyslavename);

        // change into their home directory
        sprintf(buf, "HOME=%s", owner_home.c_str());
        if (verbose)
            fprintf(verbosefile, "cd %s\n", &buf[5]);
        if (!dryrun && chdir(&buf[5]) != 0)
            perror_exit(&buf[5]);

        // adjust environment; make sure we have a PATH
        const char* path = "PATH=/usr/local/bin:/bin:/usr/bin";
        const char* ld_library_path = NULL;
        {
            extern char** environ;
            for (char** eptr = environ; *eptr; ++eptr)
                if (strncmp(*eptr, "PATH=", 5) == 0)
                    path = *eptr;
                else if (strncmp(*eptr, "LD_LIBRARY_PATH=", 16) == 0)
                    ld_library_path = *eptr;
        }
        const char* newenv[4] = { path };
        int newenvpos = 1;
        if (ld_library_path)
            newenv[newenvpos++] = ld_library_path;
        newenv[newenvpos++] = buf; // HOME
        newenv[newenvpos++] = NULL;

        // create command
        const char* newargv[6] = { owner_sh.c_str() };
        int newargvpos = 1;
        if (makepty)
            newargv[newargvpos++] = "-l";
        newargv[newargvpos++] = "-c";
        newargv[newargvpos++] = argv[3];
        newargv[newargvpos++] = NULL;

        if (!dryrun) {
            int f = open(owner_sh.c_str(), O_RDONLY);
            if (f < 0)
                perror_exit(("open" + owner_sh).c_str());
            close(f);
        }

        // close stdin (jailfiles), reopen to /dev/null
        if (caller_tty < 0) {
            close(0);
            (void) open("/dev/null", O_RDONLY);
        } else if (caller_tty != 0) {
            dup2(caller_tty, 0);
            close(caller_tty);
        }

        if (verbose) {
            for (int i = 0; newenv[i]; ++i)
                fprintf(verbosefile, "%s ", newenv[i]);
            for (int i = 0; i < newargvpos - 2; ++i)
                fprintf(verbosefile, "%s ", newargv[i]);
            fprintf(verbosefile, "'%s'\n", newargv[newargvpos - 2]);
        }

        if (!dryrun) {
            pid_t child = (makepty ? fork() : 0);
            if (child < 0)
                perror_exit("fork");
            else if (child == 0) {
                if (makepty) {
                    if (setsid() == -1)
                        perror_exit("setsid");
                    int ptyslave = open(ptyslavename, O_RDWR);
                    if (ptyslave == -1)
                        perror_exit(ptyslavename);
#ifdef TIOCGWINSZ
                    struct winsize ws;
                    ioctl(ptyslave, TIOCGWINSZ, &ws);
                    ws.ws_row = 24;
                    ws.ws_col = 80;
                    ioctl(ptyslave, TIOCSWINSZ, &ws);
#endif
                    struct termios tty;
                    if (tcgetattr(ptyslave, &tty) >= 0) {
                        tty.c_oflag = 0; // no NL->NLCR xlation, no other proc.
                        tcsetattr(ptyslave, TCSANOW, &tty);
                    }
                    dup2(ptyslave, STDOUT_FILENO);
                    dup2(ptyslave, STDERR_FILENO);
                    close(ptymaster);
                    close(ptyslave);
                }
                // restore all signals to their default actions
                // (e.g., PHP may have ignored SIGPIPE; don't want that
                // to propagate to student code!)
                for (int sig = 1; sig < NSIG; ++sig)
                    signal(sig, SIG_DFL);
                if (execve(newargv[0], (char* const*) newargv,
                           (char* const*) newenv) != 0)
                    perror_exit(("exec" + owner_sh).c_str());
            } else {
                assert(makepty);
                handle_child(child, ptymaster);
            }
        }
    }

    exit(0);
}