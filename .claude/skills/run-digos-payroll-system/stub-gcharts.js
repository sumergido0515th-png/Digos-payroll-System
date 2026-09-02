/* Minimal stand-in for https://www.gstatic.com/charts/loader.js, just enough
   for dashboard.php's usage. Not a chart-rendering test - functional only. */
(function () {
  function DataTable() { this.cols = []; this.rows = []; }
  DataTable.prototype.addColumn = function (t, l) { this.cols.push([t, l]); };
  DataTable.prototype.addRow = function (r) { this.rows.push(r); };

  function Chart() {}
  Chart.prototype.draw = function () {};

  window.google = {
    charts: {
      load: function () {},
      setOnLoadCallback: function (cb) { setTimeout(cb, 0); }
    },
    visualization: {
      DataTable: DataTable,
      ColumnChart: Chart,
      PieChart: Chart
    }
  };
})();
