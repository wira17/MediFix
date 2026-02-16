<?php
// Current page detection
$nama = $_SESSION['nama'] ?? 'Pengguna';
$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>
<!-- Sidebar -->
<aside class="main-sidebar">
  <section class="sidebar">
    <div class="user-panel">
      <div class="pull-left image">
        <i class="fa fa-user-circle" style="font-size: 45px; color: white;"></i>
      </div>
      <div class="pull-left info">
        <p><?= htmlspecialchars($nama) ?></p>
        <a href="#"><i class="fa fa-circle text-success"></i> Online</a>
      </div>
    </div>

    <ul class="sidebar-menu" data-widget="tree">
      <li class="header">MENU NAVIGASI</li>
      
      <li class="<?= $current_page == 'dashboard' ? 'active' : '' ?>">
        <a href="dashboard.php">
          <i class="fa fa-dashboard"></i> <span>DASHBOARD</span>
        </a>
      </li>
      
      <?php if (boleh('anjungan')): ?>
      <li class="<?= $current_page == 'anjungan' ? 'active' : '' ?>">
        <a href="anjungan.php" target="_blank">
          <i class="fa fa-building"></i> <span>ANJUNGAN PASIEN</span>
        </a>
      </li>
      <?php endif; ?>
      
      <?php if (boleh('admisi')): ?>
      <li class="treeview <?= in_array($current_page, ['admisi_dashboard', 'data_antri_admisi', 'display_admisi', 'ketersediaan_kamar']) ? 'active' : '' ?>">
        <a href="#">
          <i class="fa fa-user-plus"></i>
          <span>ADMISI</span>
          <span class="pull-right-container">
            <i class="fa fa-angle-left pull-right"></i>
          </span>
        </a>
        <ul class="treeview-menu">
          
          <li class="<?= $current_page == 'data_antri_admisi' ? 'active' : '' ?>">
            <a href="data_antri_admisi.php"><i class="fa fa-circle-o"></i> Panggil Admisi</a>
          </li>
          <li class="<?= $current_page == 'display_admisi' ? 'active' : '' ?>">
            <a href="display_admisi.php" target="_blank"><i class="fa fa-circle-o"></i> Display Admisi</a>
          </li>
          <li class="<?= $current_page == 'ketersediaan_kamar' ? 'active' : '' ?>">
            <a href="ketersediaan_kamar.php" target="_blank"><i class="fa fa-circle-o"></i> Ketersediaan Kamar</a>
          </li>
          <li class="<?= $current_page == 'display_jadwal_dokter' ? 'active' : '' ?>">
            <a href="display_jadwal_dokter.php" target="_blank"><i class="fa fa-circle-o"></i> Jadwal Dokter</a>
          </li>
        </ul>
      </li>
      <?php endif; ?>
      
      <?php if (boleh('poliklinik')): ?>
      <li class="treeview <?= in_array($current_page, ['poli_dashboard', 'data_antri_poli', 'display_perpoli', 'display_poli']) ? 'active' : '' ?>">
        <a href="#">
          <i class="fa fa-hospital-o"></i>
          <span>POLIKLINIK</span>
          <span class="pull-right-container">
            <i class="fa fa-angle-left pull-right"></i>
          </span>
        </a>
        <ul class="treeview-menu">
         
          <li class="<?= $current_page == 'data_antri_poli' ? 'active' : '' ?>">
            <a href="data_antri_poli.php"><i class="fa fa-circle-o"></i> Panggil Poli</a>
          </li>
          <li class="<?= $current_page == 'display_perpoli' ? 'active' : '' ?>">
            <a href="display_perpoli.php" target="_blank"><i class="fa fa-circle-o"></i> Display Per Poli</a>
          </li>
          <li class="<?= $current_page == 'display_poli' ? 'active' : '' ?>">
            <a href="display_poli.php" target="_blank"><i class="fa fa-circle-o"></i> Display Semua Poli</a>
          </li>
          <li class="<?= $current_page == 'display_jadwal_dokter' ? 'active' : '' ?>">
            <a href="display_jadwal_dokter.php" target="_blank"><i class="fa fa-circle-o"></i> Jadwal Dokter</a>
          </li>
        </ul>
      </li>
      <?php endif; ?>
      
      <?php if (boleh('farmasi')): ?>
      <li class="treeview <?= in_array($current_page, ['farmasi_dashboard', 'data_antri_farmasi', 'display_farmasi']) ? 'active' : '' ?>">
        <a href="#">
          <i class="fa fa-medkit"></i>
          <span>FARMASI</span>
          <span class="pull-right-container">
            <i class="fa fa-angle-left pull-right"></i>
          </span>
        </a>
        <ul class="treeview-menu">
         
          <li class="<?= $current_page == 'data_antri_farmasi' ? 'active' : '' ?>">
            <a href="data_antri_farmasi.php"><i class="fa fa-circle-o"></i> Panggil Farmasi</a>
          </li>
          <li class="<?= $current_page == 'display_farmasi' ? 'active' : '' ?>">
            <a href="display_farmasi.php" target="_blank"><i class="fa fa-circle-o"></i> Display Farmasi</a>
          </li>
        </ul>
      </li>
      <?php endif; ?>
      
      <?php if (boleh('setting')): ?>
      <li class="treeview <?= in_array($current_page, ['setting_dashboard', 'setting_simrs', 'setting_fitur', 'setting_antrol', 'setting_vclaim', 'setting_loket', 'hak_akses']) ? 'active' : '' ?>">
        <a href="#">
          <i class="fa fa-cog"></i>
          <span>SETTING</span>
          <span class="pull-right-container">
            <i class="fa fa-angle-left pull-right"></i>
          </span>
        </a>
        <ul class="treeview-menu">
        
          <li class="<?= $current_page == 'setting_simrs' ? 'active' : '' ?>">
            <a href="setting_simrs.php"><i class="fa fa-circle-o"></i> Integrasi SIMRS</a>
          </li>
          <li class="<?= $current_page == 'setting_fitur' ? 'active' : '' ?>">
            <a href="setting_fitur.php"><i class="fa fa-circle-o"></i> Fitur Anjungan</a>
          </li>
          <li class="<?= $current_page == 'setting_antrol' ? 'active' : '' ?>">
            <a href="setting_antrol.php"><i class="fa fa-circle-o"></i> Bridging Antrol</a>
          </li>
          <li class="<?= $current_page == 'setting_vclaim' ? 'active' : '' ?>">
            <a href="setting_vclaim.php"><i class="fa fa-circle-o"></i> Bridging VClaim</a>
          </li>
          <li class="<?= $current_page == 'setting_loket' ? 'active' : '' ?>">
            <a href="setting_loket.php"><i class="fa fa-circle-o"></i> Loket Admisi</a>
          </li>
          <li class="<?= $current_page == 'hak_akses' ? 'active' : '' ?>">
            <a href="hak_akses.php"><i class="fa fa-circle-o"></i> Hak Akses User</a>
          </li>
        </ul>
      </li>
      <?php endif; ?>
      
      <li class="header">INFORMASI</li>
      <li>
        <a href="#" data-toggle="modal" data-target="#tentangModal">
          <i class="fa fa-info-circle"></i> <span>TENTANG MediFix</span>
        </a>
      </li>
    </ul>
  </section>
</aside>