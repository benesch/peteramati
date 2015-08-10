IP = ENV["MONSTER61_VM_IP"] || "192.168.33.10"

Vagrant.configure("2") do |config|
  config.vm.box = "ubuntu/trusty64"
  config.vm.network "private_network", ip: IP
  config.vm.hostname = "monster-dev.cs61.seas.harvard.edu"

  config.ssh.forward_agent = true

  config.vm.provider "virtualbox" do |vb|
    vb.memory = ENV["MONSTER61_VM_MEMORY"] || 1024
  end

  config.vm.provision "shell", inline: <<-SCRIPT
    set -o errexit

    # APT packages
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y -q apache2 expect-dev mysql-server gcc-multilib git libapache2-mod-php5 \
                          libcurl4-openssl-dev libpcre3-dev libprocps3-dev libreadline-dev \
                          php5-dev php5-mysql php-http php5-gd \
                          sendmail wamerican qemu-system-i386


    # PHP packages
    pecl list raphf > /dev/null || yes | pecl install raphf
    pecl list propro > /dev/null || yes | pecl install propro
    echo "; priority=20\nextension=raphf.so" > /etc/php5/mods-available/raphf.ini
    echo "; priority=20\nextension=propro.so" > /etc/php5/mods-available/propro.ini
    php5enmod raphf
    php5enmod propro
    pecl list pecl_http > /dev/null || yes | pecl install pecl_http
    echo "; priority=50\nextension=http.so" > /etc/php5/mods-available/http.ini
    php5enmod http

    # Apache config
    a2enmod php5

    [[ -L /var/www/html ]] || rm -rf /var/www/html
    ln -sf /vagrant /var/www/html

    if ! diff /etc/php5/apache2/php.ini /usr/share/php5/php.ini-production > /dev/null
    then
      cp /usr/share/doc/php5-common/examples/php.ini-development /etc/php5/apache2/php.ini
    fi

    cat > /etc/apache2/conf-available/vagrant.conf <<EOF
      User vagrant
      Group vagrant

      <Directory /var/www>
        Options Indexes Includes FollowSymLinks
        AllowOverride all
        Require all granted
        FallbackResource /index.php
      </Directory>
EOF
    a2enconf vagrant
    a2enmod rewrite
    service apache2 reload


    # cs61-monster setup
    cd /var/www/html
    rm -rf conf/options.php
    lib/createdb.sh --batch --replace --user=root monster61
    chmod 0644 conf/options.php


    # execjail setup
    rm -f jail/pa-jail /usr/local/bin/pa-jail
    (cd jail; make)

    # Vagrant can't handle chown/setgid in VirtualBox shared folders
    mv jail/pa-jail /usr/local/bin/pa-jail
    chown root:0 /usr/local/bin/pa-jail
    chmod u+s,g+s,go-w /usr/local/bin/pa-jail
    ln -sf /usr/local/bin/pa-jail jail/pa-jail

    sed -i '/safePasswords/d' conf/options.php
    echo '$Opt["hostType"] = "ubuntu1404";' >> conf/options.php
    echo '$Opt["safePasswords"] = false;' >> conf/options.php
    echo '$Opt["psetsConfig"] = "classf15/psets.json";' >> conf/options.php

    if ! id -u jail61user
    then
      useradd -d /home/jail61 -m -s /bin/bash jail61user
    fi

    echo 'enablejail /home/jail61'            > /etc/pa-jail.conf
    echo 'enableskeleton /home/jail61/skel'  >> /etc/pa-jail.conf

    chown vagrant:vagrant /home/jail61
    chmod u=rwx,g=rwxs,o-rwxs /home/jail61

    # run database migrations
    curl --silent localhost > /dev/null

    # Get sample class configuration.
    if ! ssh-keygen -F code.seas.harvard.edu
    then
      echo '|1|YF77I+DiiSB194kTN7hdEx5xtKo=|5ASEgmBYnTLvG5gQH5LIsKkvXXY= ssh-rsa AAAAB3NzaC1yc2EAAAABIwAAAQEAybSGuFXlZxkKrtTx8hhg1r66zZRiamygHGbzxfxxeprafW/ciRT2LojbSlAH01J4Tom9mCmC+hb3Jo4dHuWGS5S3o6a47rmldMzgbJoGTpEKjQ6IiC0gRQb5woKIdlcN4Q+UuVmpzmaUc5eQsJtyYFxPoy60cVVj2iTo3xG8TNRmKpVkw49uvuL4KQgMAFZCpDEehmmb3r2fqsp1596o0g3xfUHvnVHWQxqd1vPCVYESe9xLULkqWDtfRWgst0AU4ReGl4i1klA7lEbZMchlW2bq6E+yp272u5KZKh3rfVFdQ08iBXw65qEelVbC1pdFRALeL+GwC21bDyDR0HcgWw==' >> ~/.ssh/known_hosts
    fi

    if [[ ! -d classf15 ]]
    then
      git clone git@code.seas.harvard.edu:cs61-dev/cs61-peteramati.git classf15
      (cd classf15; git checkout 2015)
    fi

    for i in {0..4}
    do
      echo "Creating account student$i:password"
      lib/runsql.sh --create-user "student$i" college=1 password=password
    done

    echo "Creating account admin:password"
    lib/runsql.sh --create-user admin roles=15 password=password

    echo "Creating account tf:password"
    lib/runsql.sh --create-user tf roles=1 password=password

    # Fix PHP's wonky DNS resolution
    if ! grep -F code.seas.harvard.edu /etc/hosts > /dev/null
    then
      nslookup code.seas.harvard.edu |
        tail -2 |
        awk 'NF { print $2, "code.seas.harvard.edu" }' >> /etc/hosts
    fi

    # SSH keys
    if [[ ! -f /home/vagrant/.ssh/id_rsa ]]
    then
      ssh-keygen -f /home/vagrant/.ssh/id_rsa -t rsa -N ''
      chown vagrant:vagrant /home/vagrant/.ssh/id_rsa
      echo "*** New SSH key generated!"
      echo "***"
      cat /home/vagrant/.ssh/id_rsa.pub
      echo "***"
      echo "*** Add me to code.seas!"
    fi

    if [[ ! -f conf/gitssh_config ]]
    then
      echo "UserKnownHostsFile /dev/null" > conf/gitssh_config
      echo "StrictHostKeyChecking no" >> conf/gitssh_config
      echo "IdentityFile /home/vagrant/.ssh/id_rsa" >> conf/gitssh_config
    fi

    echo "*** "
    echo "*** Done!"
    echo "*** Create an admin account now at http://#{IP}"
    echo "*** Don't forget to add your new SSH key to code.seas if necessary"
    echo "*** "
  SCRIPT
end
